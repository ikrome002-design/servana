<?php

declare(strict_types=1);

namespace App\Domain\Files\Jobs;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Enums\FileScanStatus;
use App\Domain\Files\FilePurposeRegistry;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Files\Services\ImageSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Promote a clean, quarantined file to its private final location (Plan §65;
 * Phase 10F). Image purposes are re-encoded (metadata stripped) before promotion.
 *
 * Retry-safe: only a clean + quarantined file is eligible (an already-available
 * file is a no-op); the quarantine object is deleted only AFTER the final object is
 * verified to exist; availability is set only after verified final storage.
 */
final class FinalizeCleanFile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly int $uploadedFileId)
    {
        $this->onQueue((string) config('files.queue', 'file-scanning'));
    }

    public function handle(ImageSanitizer $sanitizer, AuditRecorder $audit): void
    {
        $file = UploadedFile::query()->find($this->uploadedFileId);

        if ($file === null
            || $file->scan_status !== FileScanStatus::Clean
            || $file->lifecycle_status !== FileLifecycleStatus::Quarantined) {
            return; // not eligible / already finalized (idempotent)
        }

        $disk = Storage::disk($file->storage_disk);
        $finalPath = trim((string) config('files.final_prefix', 'files'), '/').'/'.$file->ulid;
        $definition = FilePurposeRegistry::for($file->purpose);

        if ($definition->sanitizeImage) {
            $raw = $disk->get($file->quarantine_path);
            if ($raw === null) {
                throw new \RuntimeException('Quarantine object missing during finalization.');
            }
            $clean = $sanitizer->sanitize($raw, (string) $file->detected_mime_type);
            $disk->put($finalPath, $clean);
        } else {
            $source = $disk->readStream($file->quarantine_path);
            if ($source === null) {
                throw new \RuntimeException('Quarantine object missing during finalization.');
            }
            $disk->writeStream($finalPath, $source);
            if (is_resource($source)) {
                fclose($source);
            }
        }

        // Verify the final object exists and is non-empty BEFORE deleting quarantine.
        if (! $disk->exists($finalPath) || (int) $disk->size($finalPath) <= 0) {
            throw new \RuntimeException('Final object verification failed.');
        }

        $disk->delete($file->quarantine_path);
        $file->markAvailable($finalPath);

        $audit->record(AuditEvent::FileAvailable, null, $file->merchant_id, $file->branch_id, $file, [
            'purpose' => $file->purpose->value,
        ]);
    }
}
