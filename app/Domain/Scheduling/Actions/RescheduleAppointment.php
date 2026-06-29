<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Concerns\MapsScheduleConflict;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Services\AppointmentBranchScheduleValidator;
use App\Domain\Scheduling\Services\AppointmentStateMachine;
use App\Domain\Scheduling\Services\PersonnelSchedulingValidator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reschedule a confirmed appointment to a new interval (Plan §36, §25.2; Phase
 * 16A). Records the transition through `rescheduled` and returns the appointment
 * to `confirmed` (its documented assignment state). The new end time is
 * recomputed from the current service-duration snapshot; branch operating-hours/
 * calendar and (when assigned) the shared {@see PersonnelSchedulingValidator} are
 * revalidated for the new interval; the DB exclusion constraint is the final
 * double-booking authority (409). The old + new intervals are audited. The row is
 * locked for the whole transaction so stale/concurrent updates are rejected.
 */
final class RescheduleAppointment
{
    use MapsScheduleConflict;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly AppointmentStateMachine $stateMachine,
        private readonly AppointmentBranchScheduleValidator $branchSchedule,
        private readonly PersonnelSchedulingValidator $scheduling,
    ) {}

    public function handle(Appointment $appointment, User $actor, CarbonInterface $newStartsAt): Appointment
    {
        return DB::transaction(function () use ($appointment, $actor, $newStartsAt): Appointment {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('branch.merchant', 'service', 'client', 'assignedPersonnel');

            // confirmed → rescheduled → confirmed (records the pass-through).
            $this->stateMachine->ensure($locked->status, AppointmentStatus::Rescheduled);

            /** @var MerchantBranch $branch */
            $branch = $locked->branch;
            /** @var Service $service */
            $service = $locked->service;

            $oldStart = $locked->starts_at->copy();
            $oldEnd = $locked->ends_at->copy();

            // Normalize to UTC for storage (see CreateAppointment); business logic
            // re-derives Africa/Nairobi from the absolute instant.
            $newStart = CarbonImmutable::parse($newStartsAt)->utc();
            $newEnd = $newStart->addMinutes($service->duration_minutes);

            $this->branchSchedule->ensure($branch, $newStart, $newEnd);

            $assignedPersonnel = $locked->assignedPersonnel;
            if ($assignedPersonnel !== null) {
                /** @var Merchant $merchant */
                $merchant = $branch->merchant;
                $this->scheduling->ensure($merchant, $branch, $service, $assignedPersonnel, $newStart, $newEnd);
            }

            // Pass through `rescheduled` (frees the old reservation) then re-confirm
            // on the NEW interval (re-reserves, conflict-checked by the DB).
            $locked->status = AppointmentStatus::Rescheduled;
            $locked->save();

            $this->stateMachine->ensure(AppointmentStatus::Rescheduled, AppointmentStatus::Confirmed);

            $this->mappingScheduleConflict(function () use ($locked, $newStart, $newEnd): void {
                // fill() routes through the model casts (CarbonImmutable → timestamptz)
                // without tripping the mutable-Carbon @property type on direct assignment.
                $locked->fill([
                    'starts_at' => $newStart,
                    'ends_at' => $newEnd,
                    'status' => AppointmentStatus::Confirmed->value,
                ])->save();
            });

            $this->audit->record(
                AuditEvent::AppointmentRescheduled,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'appointment_id' => $locked->ulid,
                    'client_id' => $locked->client?->ulid,
                    'previous_starts_at' => $oldStart->toIso8601String(),
                    'previous_ends_at' => $oldEnd->toIso8601String(),
                    'new_starts_at' => $newStart->toIso8601String(),
                    'new_ends_at' => $newEnd->toIso8601String(),
                ],
            );

            return $locked->refresh();
        });
    }
}
