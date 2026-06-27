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
 * Remove quarantine objects for files that never reached `available` within the
 * quarantine window (Plan §65, §67, §73; Phase 10F) — rejected/infected/failed or
 * stuck-pending uploads. Bounded batch; idempotent; deletes ONLY the known
 * quarantine object recorded on the row (never arbitrary storage).
 */
final class DeleteExpiredQuarantineFile implements ShouldQueue
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
        $cutoff = now()->subHours((int) config('files.quarantine_retention_hours', 24));
        $deleted = 0;

        UploadedFile::query()
            ->where('lifecycle_status', FileLifecycleStatus::Quarantined->value)
            ->where('created_at', '<', $cutoff)
            ->limit($this->batch)
            ->get()
            ->each(function (UploadedFile $file) use ($audit, &$deleted): void {
                Storage::disk($file->storage_disk)->delete($file->quarantine_path);
                $file->markLifecycle(FileLifecycleStatus::Deleted);
                $audit->record(AuditEvent::FileExpiredOrDeleted, null, $file->merchant_id, $file->branch_id, $file, [
                    'purpose' => $file->purpose->value,
                    'reason' => 'quarantine_expired',
                ]);
                $deleted++;
            });

        return $deleted;
    }
}
