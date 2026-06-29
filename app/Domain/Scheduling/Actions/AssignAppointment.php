<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Concerns\MapsScheduleConflict;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Services\AppointmentBranchScheduleValidator;
use App\Domain\Scheduling\Services\AppointmentStateMachine;
use App\Domain\Scheduling\Services\PersonnelSchedulingValidator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Assign an eligible personnel member to a `scheduled` appointment, establishing
 * the reservation and confirming it (Plan §36, §25.2; Phase 16A).
 *
 * Runs the shared Phase 15B {@see PersonnelSchedulingValidator} (eligibility +
 * availability) and the branch operating-calendar gate around it; the DB
 * exclusion constraint is the final double-booking authority (mapped to 409).
 * Re-assignment of an already-assigned appointment is a TRANSFER, not an assign.
 */
final class AssignAppointment
{
    use MapsScheduleConflict;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly AppointmentStateMachine $stateMachine,
        private readonly AppointmentBranchScheduleValidator $branchSchedule,
        private readonly PersonnelSchedulingValidator $scheduling,
    ) {}

    public function handle(Appointment $appointment, User $actor, StaffProfile $staff): Appointment
    {
        return DB::transaction(function () use ($appointment, $actor, $staff): Appointment {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('branch.merchant', 'service', 'client');

            // scheduled → confirmed only (re-assignment is a transfer).
            $this->stateMachine->ensure($locked->status, AppointmentStatus::Confirmed);

            /** @var MerchantBranch $branch */
            $branch = $locked->branch;
            /** @var Service $service */
            $service = $locked->service;
            /** @var Merchant $merchant */
            $merchant = $branch->merchant;

            $this->branchSchedule->ensure($branch, $locked->starts_at, $locked->ends_at);
            $this->scheduling->ensure($merchant, $branch, $service, $staff, $locked->starts_at, $locked->ends_at);

            $this->mappingScheduleConflict(function () use ($locked, $staff): void {
                $locked->assigned_personnel_staff_profile_id = $staff->id;
                $locked->status = AppointmentStatus::Confirmed;
                $locked->save();
            });

            $this->audit->record(
                AuditEvent::AppointmentAssigned,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'appointment_id' => $locked->ulid,
                    'client_id' => $locked->client?->ulid,
                    'previous_state' => AppointmentStatus::Scheduled->value,
                    'new_state' => AppointmentStatus::Confirmed->value,
                    'previous_personnel_id' => null,
                    'new_personnel_id' => $staff->ulid,
                ],
            );

            return $locked->refresh();
        });
    }
}
