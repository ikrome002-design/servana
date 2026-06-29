<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Concerns\BuildsQueueAudit;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Exceptions\QueueConflictException;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Services\AppointmentStateMachine;
use App\Domain\Scheduling\Services\QueueAssignmentResolver;
use App\Domain\Scheduling\Services\QueueCapacityGuard;
use App\Domain\Scheduling\Services\QueuePositionService;
use App\Domain\Scheduling\Services\QueueWaitEstimator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Convert a checked-in appointment into exactly one queue entry (Plan §25.2, §37;
 * Phase 16B; appointment `checked_in → queued`). The appointment must be
 * `checked_in` and belong to the same merchant + branch as the target queue. The
 * queue entry and the appointment status change commit or roll back together under
 * a row lock + the per-branch advisory lock; the `UNIQUE (appointment_id)` on
 * queue_entries makes repeated conversion deterministic (409). No service session
 * or duplicate client/walk-in/service is created.
 */
final class ConvertAppointmentToQueue
{
    use BuildsQueueAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly AppointmentStateMachine $machine,
        private readonly QueuePositionService $position,
        private readonly QueueCapacityGuard $capacity,
        private readonly QueueAssignmentResolver $resolver,
        private readonly QueueWaitEstimator $estimator,
    ) {}

    public function handle(
        Appointment $appointment,
        User $actor,
        QueueAssignmentMode $mode,
        ?StaffProfile $target = null,
        ?StaffProfile $preferred = null,
    ): QueueEntry {
        return DB::transaction(function () use ($appointment, $actor, $mode, $target, $preferred): QueueEntry {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->position->lock($locked->merchant_id, $locked->branch_id);

            // One queue entry per appointment (deterministic 409 on repeat) — checked
            // BEFORE the state machine so a re-queue of an already-queued appointment
            // returns the conversion conflict, not a generic invalid-transition.
            if (QueueEntry::query()->where('appointment_id', $locked->id)->exists()) {
                throw QueueConflictException::conversionExists();
            }

            // checked_in → queued only (422 invalid_state_transition otherwise).
            $this->machine->ensure($locked->status, AppointmentStatus::Queued);

            /** @var MerchantBranch $branch */
            $branch = $locked->branch()->firstOrFail();
            $this->capacity->ensureOpenForNewEntry($branch);

            $preferred ??= $locked->preferred_personnel_staff_profile_id !== null
                ? $locked->preferredPersonnel()->first()
                : null;

            $entry = QueueEntry::query()->create([
                'merchant_id' => $locked->merchant_id,
                'branch_id' => $locked->branch_id,
                'appointment_id' => $locked->id,
                'client_id' => $locked->client_id,
                'service_id' => $locked->service_id,
                'preferred_personnel_staff_profile_id' => $preferred?->id,
                'assignment_mode' => $mode,
                'status' => QueueEntryStatus::Waiting,
                'position' => $this->position->nextPosition($locked->branch_id),
                'queued_at' => now(),
                'estimated_wait_minutes' => 0,
                'created_by' => $actor->id,
            ]);

            // The appointment may already carry an assigned personnel member; honour
            // an explicit target/preferred, else fall back to the assigned member.
            $target ??= $locked->assigned_personnel_staff_profile_id !== null && $mode === QueueAssignmentMode::Manual
                ? $locked->assignedPersonnel()->first()
                : null;

            $assigned = $this->resolver->resolve($entry, $mode, $target, $preferred);
            if ($assigned !== null) {
                $entry->staff_profile_id = $assigned->id;
                $entry->status = QueueEntryStatus::Assigned;
                $entry->assigned_at = now();
                $entry->save();
            }

            $locked->status = AppointmentStatus::Queued;
            $locked->save();

            $entry->estimated_wait_minutes = $this->estimator->estimateFor($entry);
            $entry->save();
            $this->estimator->recalculateBranch($locked->branch_id);

            $this->audit->record(
                AuditEvent::AppointmentQueued,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'appointment_id' => $locked->ulid,
                    'queue_entry_id' => $entry->ulid,
                    'previous_state' => AppointmentStatus::CheckedIn->value,
                    'new_state' => AppointmentStatus::Queued->value,
                ],
            );

            $this->audit->record(
                AuditEvent::QueueEntryCreated,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $entry,
                $this->queueAuditContext($entry, [
                    'source' => 'appointment',
                    'appointment_id' => $locked->ulid,
                    'new_state' => $entry->status->value,
                    'new_position' => $entry->position,
                ]),
            );

            return $entry->load(['client', 'service', 'assignedPersonnel', 'preferredPersonnel', 'appointment']);
        });
    }
}
