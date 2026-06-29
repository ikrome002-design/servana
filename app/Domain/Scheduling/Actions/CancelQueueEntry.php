<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Scheduling\Concerns\BuildsQueueAudit;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Exceptions\QueueEntryStateException;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Services\QueueEntryStateMachine;
use App\Domain\Scheduling\Services\QueuePositionService;
use App\Domain\Scheduling\Services\QueueWaitEstimator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a queue entry before service (Plan §37; Phase 16B; waiting/assigned/called
 * → cancelled). A non-empty reason is required. Releases + compacts the active
 * queue position; the record is preserved (no hard delete). Distinct from no-show.
 */
final class CancelQueueEntry
{
    use BuildsQueueAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly QueueEntryStateMachine $machine,
        private readonly QueuePositionService $position,
        private readonly QueueWaitEstimator $estimator,
    ) {}

    public function handle(QueueEntry $entry, User $actor, string $reason): QueueEntry
    {
        if (trim($reason) === '') {
            throw QueueEntryStateException::reasonRequired();
        }

        return DB::transaction(function () use ($entry, $actor, $reason): QueueEntry {
            $this->position->lock($entry->merchant_id, $entry->branch_id);

            /** @var QueueEntry $locked */
            $locked = QueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            $previousState = $locked->status;
            $this->machine->ensure($locked->status, QueueEntryStatus::Cancelled);

            $locked->status = QueueEntryStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $reason;
            $locked->save();

            $this->position->compact($locked->branch_id);
            $this->estimator->recalculateBranch($locked->branch_id);
            $locked->refresh()->load(['client', 'service', 'assignedPersonnel', 'preferredPersonnel']);

            $this->audit->record(
                AuditEvent::QueueEntryCancelled,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->queueAuditContext($locked, [
                    'previous_state' => $previousState->value,
                    'new_state' => QueueEntryStatus::Cancelled->value,
                    'reason' => $reason,
                ]),
            );

            return $locked;
        });
    }
}
