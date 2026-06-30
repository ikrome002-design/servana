<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Scheduling\Concerns\BuildsQueueAudit;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Services\QueueEntryStateMachine;
use App\Domain\Scheduling\Services\QueuePersonnelAssignmentValidator;
use App\Domain\Scheduling\Services\QueuePositionService;
use App\Domain\Scheduling\Services\QueueWaitEstimator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Call an assigned queue entry's client for service (Plan §37; Phase 16B; assigned
 * → called). Revalidates the assigned personnel; sets `called_at`. Creates NO
 * service session (16C).
 */
final class CallQueueEntry
{
    use BuildsQueueAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly QueueEntryStateMachine $machine,
        private readonly QueuePositionService $position,
        private readonly QueuePersonnelAssignmentValidator $validator,
        private readonly QueueWaitEstimator $estimator,
    ) {}

    public function handle(QueueEntry $entry, User $actor): QueueEntry
    {
        return DB::transaction(function () use ($entry, $actor): QueueEntry {
            $this->position->lock($entry->merchant_id, $entry->branch_id);

            /** @var QueueEntry $locked */
            $locked = QueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, QueueEntryStatus::Called);

            $staff = $locked->assignedPersonnel()->first();
            if ($staff !== null) {
                $this->validator->ensure($locked, $staff);
            }

            $locked->status = QueueEntryStatus::Called;
            $locked->called_at = now();
            $locked->save();

            $this->estimator->recalculateBranch($locked->branch_id);
            $locked->refresh()->load(['client', 'service', 'assignedPersonnel', 'preferredPersonnel']);

            $this->audit->record(
                AuditEvent::QueueEntryCalled,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->queueAuditContext($locked, [
                    'previous_state' => QueueEntryStatus::Assigned->value,
                    'new_state' => QueueEntryStatus::Called->value,
                ]),
            );

            return $locked;
        });
    }
}
