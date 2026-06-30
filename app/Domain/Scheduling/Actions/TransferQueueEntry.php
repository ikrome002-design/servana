<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Concerns\BuildsQueueAudit;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Exceptions\QueueEntryStateException;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Services\QueueEntryStateMachine;
use App\Domain\Scheduling\Services\QueuePersonnelAssignmentValidator;
use App\Domain\Scheduling\Services\QueuePositionService;
use App\Domain\Scheduling\Services\QueueWaitEstimator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Transfer a queue entry (Plan §37; Phase 16B; waiting/assigned/called →
 * transferred → assigned|waiting). Front Office only. Requires a non-empty reason
 * and either a DIFFERENT eligible target (→ assigned, target revalidated) or an
 * explicit return to the waiting pool (→ waiting). Source personnel metadata is
 * preserved; queue integrity is kept under the per-branch advisory lock.
 */
final class TransferQueueEntry
{
    use BuildsQueueAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly QueueEntryStateMachine $machine,
        private readonly QueuePositionService $position,
        private readonly QueuePersonnelAssignmentValidator $validator,
        private readonly QueueWaitEstimator $estimator,
    ) {}

    public function handle(QueueEntry $entry, User $actor, ?StaffProfile $target, string $reason): QueueEntry
    {
        if (trim($reason) === '') {
            throw QueueEntryStateException::reasonRequired();
        }

        return DB::transaction(function () use ($entry, $actor, $target, $reason): QueueEntry {
            $this->position->lock($entry->merchant_id, $entry->branch_id);

            /** @var QueueEntry $locked */
            $locked = QueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            $fromStaffId = $locked->staff_profile_id;
            $fromUlid = $locked->assignedPersonnel()->first()?->ulid;

            if ($target !== null && $target->id === $fromStaffId) {
                throw QueueEntryStateException::sameTransferTarget();
            }

            // Source → transferred (transient) → assigned | waiting.
            $this->machine->ensure($locked->status, QueueEntryStatus::Transferred);
            $finalState = $target !== null ? QueueEntryStatus::Assigned : QueueEntryStatus::Waiting;
            $this->machine->ensure(QueueEntryStatus::Transferred, $finalState);

            if ($target !== null) {
                $this->validator->ensure($locked, $target);
            }

            $previousState = $locked->status;
            $locked->transferred_at = now();
            $locked->transferred_from_staff_profile_id = $fromStaffId;
            $locked->transferred_to_staff_profile_id = $target?->id;
            $locked->transfer_reason = $reason;
            $locked->status = $finalState;

            if ($target !== null) {
                $locked->staff_profile_id = $target->id;
                $locked->assignment_mode = QueueAssignmentMode::Manual;
                $locked->assigned_at = now();
            } else {
                $locked->staff_profile_id = null;
            }

            $locked->save();

            $this->estimator->recalculateBranch($locked->branch_id);
            $locked->refresh()->load(['client', 'service', 'assignedPersonnel', 'preferredPersonnel']);

            $this->audit->record(
                AuditEvent::QueueEntryTransferred,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->queueAuditContext($locked, [
                    'previous_state' => $previousState->value,
                    'new_state' => $finalState->value,
                    'previous_personnel_id' => $fromUlid,
                    'new_personnel_id' => $target?->ulid,
                    'reason' => $reason,
                ]),
            );

            return $locked;
        });
    }
}
