<?php

declare(strict_types=1);

namespace App\Domain\Files\Jobs;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Models\UploadedFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Expire generated-purpose files whose retention window has elapsed (Plan §65, §67;
 * Phase 10F). Bounded batch; idempotent (only available rows with an elapsed
 * retention_until). The final object is removed and the row marked expired. Does
 * NOT create the future finance_exports table.
 */
final class ExpireSignedExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $batch = 200)
    {
        $this->onQueue((string) config('files.queue', 'file-scanning'));
    }

    public function handle(AuditRecorder $audit): int
    {
        $expired = 0;

        UploadedFile::query()
            // `revoked` is swept too (PH23-EXP-001): a revoked export's object must still be
            // removed at its retention window. Revoked rows converge to `expired` here, so the
            // sweep never re-selects them and stays bounded.
            ->whereIn('lifecycle_status', [
                FileLifecycleStatus::Available->value,
                FileLifecycleStatus::Revoked->value,
            ])
            ->whereNotNull('retention_until')
            ->where('retention_until', '<', now())
            ->limit($this->batch)
            ->get()
            ->each(function (UploadedFile $file) use ($audit, &$expired): void {
                if ($file->final_path !== null) {
                    Storage::disk($file->storage_disk)->delete($file->final_path);
                }
                $file->markLifecycle(FileLifecycleStatus::Expired);
                $audit->record(AuditEvent::FileExpiredOrDeleted, null, $file->merchant_id, $file->branch_id, $file, [
                    'purpose' => $file->purpose->value,
                    'reason' => 'retention_elapsed',
                ]);
                $expired++;
            });

        return $expired;
    }
}
