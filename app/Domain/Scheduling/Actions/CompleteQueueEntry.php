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
 * Complete an in-service queue entry (Plan §37; Phase 16B; in_service →
 * completed). Sets `completed_at`, releases the active queue position (the waiting
 * sequence is compacted), and recalculates estimates. Phase 16C extends this action
 * to complete the linked service session before Phase 17 can invoice it — Phase 16B
 * creates NO invoice, payment, receipt, or commission preview.
 */
final class CompleteQueueEntry
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

            $this->machine->ensure($locked->status, QueueEntryStatus::Completed);

            $locked->status = QueueEntryStatus::Completed;
            $locked->completed_at = now();
            $locked->save();

            // Released from the active-ordered set → compact the remaining queue.
            $this->position->compact($locked->branch_id);
            $this->estimator->recalculateBranch($locked->branch_id);
            $locked->refresh()->load(['client', 'service', 'assignedPersonnel', 'preferredPersonnel']);

            $this->audit->record(
                AuditEvent::QueueEntryCompleted,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->queueAuditContext($locked, [
                    'previous_state' => QueueEntryStatus::InService->value,
                    'new_state' => QueueEntryStatus::Completed->value,
                ]),
            );

            return $locked;
        });
    }
}
