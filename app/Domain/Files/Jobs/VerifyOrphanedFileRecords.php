<?php

declare(strict_types=1);

namespace App\Domain\Files\Jobs;

use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Models\UploadedFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Report database/object mismatches safely (Plan §65, §67; Phase 10F):
 *   - available rows whose final object is missing;
 *   - quarantined rows whose quarantine object is missing.
 *
 * REPORT ONLY — it never deletes unknown production objects; remediation of any
 * mismatch is a reviewed operator action. Logs ULIDs only (no paths/hashes).
 *
 * @return array{available_missing: int, quarantine_missing: int}
 */
final class VerifyOrphanedFileRecords implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $batch = 500)
    {
        $this->onQueue((string) config('files.queue', 'file-scanning'));
    }

    /** @return array{available_missing: int, quarantine_missing: int} */
    public function handle(): array
    {
        $availableMissing = [];
        $quarantineMissing = [];

        UploadedFile::query()
            ->whereIn('lifecycle_status', [
                FileLifecycleStatus::Available->value,
                FileLifecycleStatus::Quarantined->value,
            ])
            ->limit($this->batch)
            ->get()
            ->each(function (UploadedFile $file) use (&$availableMissing, &$quarantineMissing): void {
                $disk = Storage::disk($file->storage_disk);

                if ($file->lifecycle_status === FileLifecycleStatus::Available
                    && ($file->final_path === null || ! $disk->exists($file->final_path))) {
                    $availableMissing[] = $file->ulid;
                }

                if ($file->lifecycle_status === FileLifecycleStatus::Quarantined
                    && ! $disk->exists($file->quarantine_path)) {
                    $quarantineMissing[] = $file->ulid;
                }
            });

        if ($availableMissing !== [] || $quarantineMissing !== []) {
            // ULIDs only — never paths/hashes. No automatic deletion.
            Log::warning('Orphaned file records detected (operator review required).', [
                'available_missing_ulids' => $availableMissing,
                'quarantine_missing_ulids' => $quarantineMissing,
            ]);
        }

        return [
            'available_missing' => count($availableMissing),
            'quarantine_missing' => count($quarantineMissing),
        ];
    }
}
