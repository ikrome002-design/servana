<?php

declare(strict_types=1);

namespace App\Domain\Files\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\FilePurposeRegistry;
use App\Domain\Files\Jobs\ScanUploadedFile;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reusable secure upload pipeline (Plan §9 rule 10, §65, §73; Phase 10F).
 *
 * Authorise the purpose/target → validate metadata → reject dangerous/spoofed
 * content BEFORE any bytes are persisted → stream to private quarantine →
 * streaming SHA-256 + true byte count → server magic-byte MIME → create a
 * pending/quarantined record → dispatch the scan. Internal ids, paths and the
 * hash are never returned. Rejected uploads are never stored.
 */
final class FileUploadPipeline
{
    /** Extensions that must never appear as an inner segment (double-extension attack). */
    private const DANGEROUS_SEGMENTS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'exe', 'sh',
        'bat', 'cmd', 'com', 'js', 'mjs', 'jsp', 'asp', 'aspx', 'pl', 'py', 'rb',
        'svg', 'htm', 'html', 'htaccess', 'dll', 'so', 'bin',
    ];

    /** Allowed extensions per detected raster MIME. */
    private const MIME_EXTENSIONS = [
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/webp' => ['webp'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function accept(FilePurpose $purpose, HttpUploadedFile $upload, User $actor, ?int $ownerUserId = null): UploadedFile
    {
        $definition = FilePurposeRegistry::for($purpose);

        // 1. Only currently-uploadable purposes; generated-only purposes are rejected.
        if (! $definition->uploadable) {
            $this->reject($actor, $purpose, 'not_uploadable');
        }

        // 2. Scope requirements (authorisation of the target before storage).
        $merchantId = $this->context->merchantId();
        if ($definition->requiresMerchant && $merchantId === null) {
            $this->reject($actor, $purpose, 'merchant_required');
        }
        $branchId = $definition->requiresBranch ? $this->currentBranchId() : null;
        if ($definition->requiresBranch && $branchId === null) {
            $this->reject($actor, $purpose, 'branch_required');
        }
        $owner = $definition->requiresOwner ? ($ownerUserId ?? $actor->id) : $ownerUserId;

        // 3. Server-side content validation (never trust client MIME/filename/size).
        $path = (string) $upload->getRealPath();
        $size = $path !== '' && is_file($path) ? (int) filesize($path) : 0;
        if ($size <= 0) {
            $this->reject($actor, $purpose, 'zero_byte');
        }
        if ($size > $definition->maxBytes) {
            $this->reject($actor, $purpose, 'oversize');
        }

        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! $definition->allowsMime($detected)) {
            $this->reject($actor, $purpose, 'unsupported_mime');
        }

        $clientName = (string) $upload->getClientOriginalName();
        $extension = strtolower((string) $upload->getClientOriginalExtension());

        $this->assertNoDangerousSegments($actor, $purpose, $clientName);

        if (! $definition->allowsExtension($extension)
            || ! in_array($extension, self::MIME_EXTENSIONS[$detected] ?? [], true)) {
            $this->reject($actor, $purpose, 'extension_mime_mismatch');
        }

        // Declared MIME spoof: a materially different top-level type than detected.
        $declared = (string) $upload->getClientMimeType();
        if ($declared !== '' && $this->topLevel($declared) !== $this->topLevel($detected)) {
            $this->reject($actor, $purpose, 'mime_spoof');
        }

        // 4. Streaming SHA-256 (hash_file streams; never loads the whole file).
        $sha256 = (string) hash_file('sha256', $path);

        // 5. Persist to private quarantine.
        $ulid = (string) Str::ulid();
        $disk = (string) config('files.disk');
        $quarantinePath = trim((string) config('files.quarantine_prefix', 'quarantine'), '/').'/'.$ulid;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->reject($actor, $purpose, 'unreadable');
        }
        Storage::disk($disk)->put($quarantinePath, $handle);

        // 6. Create the pending/quarantined record (status NOT mass-assignable).
        $file = new UploadedFile;
        $file->fill([
            'merchant_id' => $merchantId,
            'branch_id' => $branchId,
            'owner_user_id' => $owner,
            'purpose' => $purpose->value,
            'storage_disk' => $disk,
            'quarantine_path' => $quarantinePath,
            'original_filename_encrypted' => $clientName,
            'safe_download_filename' => $this->safeFilename($clientName, $extension),
            'declared_mime_type' => $declared !== '' ? $declared : null,
            'detected_mime_type' => $detected,
            'extension' => $extension,
            'size_bytes' => $size,
            'sha256' => $sha256,
            'retention_until' => $definition->retentionDays !== null ? now()->addDays($definition->retentionDays) : null,
            'uploaded_by' => $actor->id,
        ]);
        $file->save();

        $this->audit->record(AuditEvent::FileUploadAccepted, $actor, $merchantId, $branchId, $file, [
            'purpose' => $purpose->value,
            'detected_mime' => $detected,
            'size_bytes' => $size,
        ]);

        ScanUploadedFile::dispatch($file->id);

        return $file;
    }

    private function assertNoDangerousSegments(User $actor, FilePurpose $purpose, string $clientName): void
    {
        $segments = array_map('strtolower', explode('.', $clientName));
        // Every segment except the final extension must not be a dangerous token.
        $inner = array_slice($segments, 1, max(0, count($segments) - 2));
        foreach ($inner as $segment) {
            if (in_array($segment, self::DANGEROUS_SEGMENTS, true)) {
                $this->reject($actor, $purpose, 'double_extension');
            }
        }
    }

    private function topLevel(string $mime): string
    {
        return explode('/', $mime)[0];
    }

    private function safeFilename(string $original, string $extension): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '_', $base) ?? 'file';
        $base = trim($base, '_') ?: 'file';

        return substr($base, 0, 100).'.'.$extension;
    }

    /**
     * Reject pre-storage with a safe code (no dangerous bytes persisted), audit it,
     * and return a 422 — never leaking the filename/bytes.
     */
    private function reject(User $actor, FilePurpose $purpose, string $code): never
    {
        $this->audit->record(AuditEvent::FileUploadRejected, $actor, $this->context->merchantId(), null, null, [
            'purpose' => $purpose->value,
            'reason' => $code,
        ]);

        throw ValidationException::withMessages(['file' => "File rejected: {$code}."]);
    }

    private function currentBranchId(): ?int
    {
        return $this->context->branchIds()[0] ?? null;
    }
}
