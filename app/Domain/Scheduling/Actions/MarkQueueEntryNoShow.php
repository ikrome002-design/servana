<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Scheduling\Concerns\BuildsQueueAudit;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Services\QueueEntryStateMachine;
use App\Domain\Scheduling\Services\QueuePositionService;
use App\Domain\Scheduling\Services\QueueWaitEstimator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mark a queue entry as a no-show (Plan §37; Phase 16B; waiting/assigned/called →
 * no_show). Sets `no_show_at`, releases + compacts the active position, and is
 * distinct from cancellation. It never marks the personnel member unavailable.
 */
final class MarkQueueEntryNoShow
{
    use BuildsQueueAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly QueueEntryStateMachine $machine,
        private readonly QueuePositionService $position,
        private readonly QueueWaitEstimator $estimator,
    ) {}

    public function handle(QueueEntry $entry, User $actor): QueueEntry
    {
        return DB::transaction(function () use ($entry, $actor): QueueEntry {
            $this->position->lock($entry->merchant_id, $entry->branch_id);

            /** @var QueueEntry $locked */
            $locked = QueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            $previousState = $locked->status;
            $this->machine->ensure($locked->status, QueueEntryStatus::NoShow);

            $locked->status = QueueEntryStatus::NoShow;
            $locked->no_show_at = now();
            $locked->save();

            $this->position->compact($locked->branch_id);
            $this->estimator->recalculateBranch($locked->branch_id);
            $locked->refresh()->load(['client', 'service', 'assignedPersonnel', 'preferredPersonnel']);

            $this->audit->record(
                AuditEvent::QueueEntryNoShow,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->queueAuditContext($locked, [
                    'previous_state' => $previousState->value,
                    'new_state' => QueueEntryStatus::NoShow->value,
                ]),
            );

            return $locked;
        });
    }
}
