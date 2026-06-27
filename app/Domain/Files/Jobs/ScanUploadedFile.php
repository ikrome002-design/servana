<?php

declare(strict_types=1);

namespace App\Domain\Files\Jobs;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Files\Contracts\FileScanner;
use App\Domain\Files\Enums\FileScanResult;
use App\Domain\Files\Enums\FileScanStatus;
use App\Domain\Files\Models\FileScanEvent;
use App\Domain\Files\Models\UploadedFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Scan a quarantined upload with the malware scanner (Plan §65, §73; Phase 10F).
 *
 * Idempotent (only a still-pending file is scanned); appends exactly one
 * file_scan_events row per actual scan; transitions to clean/infected; a transient
 * scan/engine error throws so the bounded retry re-scans (the file stays pending),
 * and only after retries are exhausted is it marked scan_failed in failed().
 * Clean files dispatch FinalizeCleanFile. Infected/failed files never finalize.
 */
final class ScanUploadedFile implements ShouldQueue
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

    public function handle(FileScanner $scanner, AuditRecorder $audit): void
    {
        $file = UploadedFile::query()->find($this->uploadedFileId);

        // Idempotent: only a still-pending file is scanned.
        if ($file === null || $file->scan_status !== FileScanStatus::Pending) {
            return;
        }

        $stream = Storage::disk($file->storage_disk)->readStream($file->quarantine_path);
        if ($stream === null) {
            throw new \RuntimeException('Quarantine object unreadable.');
        }

        try {
            $outcome = $scanner->scanResource($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // One row per actual scan (safe metadata only).
        FileScanEvent::create([
            'uploaded_file_id' => $file->id,
            'scanner' => $outcome->scanner,
            'engine_version' => $outcome->engineVersion,
            'signature_version' => $outcome->signatureVersion,
            'result' => $outcome->result->value,
            'malware_name' => $outcome->malwareName,
            'error_code' => $outcome->errorCode,
            'scanned_at' => now(),
        ]);

        match ($outcome->result) {
            FileScanResult::Clean => $this->onClean($file, $audit),
            FileScanResult::Infected => $this->onInfected($file, $audit),
            FileScanResult::Error => throw new \RuntimeException('Scan error: '.($outcome->errorCode ?? 'unknown')),
        };
    }

    private function onClean(UploadedFile $file, AuditRecorder $audit): void
    {
        $file->markScanClean();
        $audit->record(AuditEvent::FileScanClean, null, $file->merchant_id, $file->branch_id, $file, [
            'purpose' => $file->purpose->value,
        ]);
        FinalizeCleanFile::dispatch($file->id);
    }

    private function onInfected(UploadedFile $file, AuditRecorder $audit): void
    {
        $file->markInfected();
        $audit->record(AuditEvent::FileScanInfected, null, $file->merchant_id, $file->branch_id, $file, [
            'purpose' => $file->purpose->value,
            // Signature name only — never the payload.
            'signature' => $file->scanEvents()->latest('id')->value('malware_name'),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $file = UploadedFile::query()->find($this->uploadedFileId);
        if ($file === null || $file->scan_status !== FileScanStatus::Pending) {
            return;
        }

        $file->markScanFailed();
        app(AuditRecorder::class)->record(
            AuditEvent::FileScanFailed,
            null,
            $file->merchant_id,
            $file->branch_id,
            $file,
            ['purpose' => $file->purpose->value],
        );
    }
}
