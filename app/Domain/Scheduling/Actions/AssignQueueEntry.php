<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Concerns\BuildsQueueAudit;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Exceptions\SchedulingValidationException;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Services\QueueAssignmentResolver;
use App\Domain\Scheduling\Services\QueueEntryStateMachine;
use App\Domain\Scheduling\Services\QueuePositionService;
use App\Domain\Scheduling\Services\QueueWaitEstimator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Assign personnel to a waiting queue entry (Plan §37; Phase 16B; waiting →
 * assigned). Supports next_available (deterministic selector), manual (explicit
 * target), and preferred_personnel. Overriding a recorded preferred request to a
 * different person requires a non-empty reason (audited). Personnel are revalidated
 * (eligibility/availability/branch/lifecycle/not-busy) via the shared validator. A
 * next_available/preferred resolution that finds nobody leaves the entry waiting.
 */
final class AssignQueueEntry
{
    use BuildsQueueAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly QueueEntryStateMachine $machine,
        private readonly QueuePositionService $position,
        private readonly QueueAssignmentResolver $resolver,
        private readonly QueueWaitEstimator $estimator,
    ) {}

    public function handle(
        QueueEntry $entry,
        User $actor,
        QueueAssignmentMode $mode,
        ?StaffProfile $target = null,
        ?StaffProfile $preferred = null,
        ?string $overrideReason = null,
    ): QueueEntry {
        return DB::transaction(function () use ($entry, $actor, $mode, $target, $preferred, $overrideReason): QueueEntry {
            $this->position->lock($entry->merchant_id, $entry->branch_id);

            /** @var QueueEntry $locked */
            $locked = QueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            // waiting → assigned only.
            $this->machine->ensure($locked->status, QueueEntryStatus::Assigned);

            $assigned = $this->resolver->resolve($locked, $mode, $target, $preferred ?? $this->recordedPreferred($locked));

            // No one available right now: leave the entry waiting (preference kept).
            if ($assigned === null) {
                $this->estimator->recalculateBranch($locked->branch_id);

                return $locked->refresh()->load(['client', 'service', 'assignedPersonnel', 'preferredPersonnel']);
            }

            $overrides = $locked->preferred_personnel_staff_profile_id !== null
                && $assigned->id !== $locked->preferred_personnel_staff_profile_id;

            if ($overrides && ($overrideReason === null || trim($overrideReason) === '')) {
                throw new SchedulingValidationException('reason_required', 'A reason is required to override the preferred personnel request.');
            }

            $previousPersonnel = $locked->assignedPersonnel()->first()?->ulid;

            $locked->staff_profile_id = $assigned->id;
            $locked->assignment_mode = $mode;
            $locked->status = QueueEntryStatus::Assigned;
            $locked->assigned_at = now();
            if ($overrides) {
                $locked->preferred_personnel_override_reason = $overrideReason;
            }
            $locked->save();

            $this->estimator->recalculateBranch($locked->branch_id);
            $locked->refresh()->load(['client', 'service', 'assignedPersonnel', 'preferredPersonnel']);

            $this->audit->record(
                AuditEvent::QueueEntryAssigned,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->queueAuditContext($locked, [
                    'previous_state' => QueueEntryStatus::Waiting->value,
                    'new_state' => QueueEntryStatus::Assigned->value,
                    'previous_personnel_id' => $previousPersonnel,
                    'new_personnel_id' => $assigned->ulid,
                    'override_reason' => $overrides ? $overrideReason : null,
                ]),
            );

            return $locked;
        });
    }

    private function recordedPreferred(QueueEntry $entry): ?StaffProfile
    {
        return $entry->preferred_personnel_staff_profile_id !== null
            ? $entry->preferredPersonnel()->first()
            : null;
    }
}
