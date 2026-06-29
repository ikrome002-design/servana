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
use App\Domain\Scheduling\Exceptions\AppointmentStateException;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Services\AppointmentBranchScheduleValidator;
use App\Domain\Scheduling\Services\PersonnelSchedulingValidator;
use App\Models\User;
use App\Support\Redaction\Redactor;
use Illuminate\Support\Facades\DB;

/**
 * Transfer an assigned appointment to a different eligible personnel member
 * (Plan §36; Phase 16A). Front Office only (Branch Manager is rejected by the
 * policy + route). The client, service, branch, and interval are preserved; only
 * the assigned personnel changes. The shared Phase 15B
 * {@see PersonnelSchedulingValidator} + branch-calendar gate revalidate the new
 * personnel; the DB exclusion constraint is the final double-booking authority
 * (409). The old and new personnel ULIDs are audited.
 */
final class TransferAppointment
{
    use MapsScheduleConflict;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly AppointmentBranchScheduleValidator $branchSchedule,
        private readonly PersonnelSchedulingValidator $scheduling,
        private readonly Redactor $redactor,
    ) {}

    public function handle(Appointment $appointment, User $actor, StaffProfile $target, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $actor, $target, $reason): Appointment {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('branch.merchant', 'service', 'client', 'assignedPersonnel');

            // Only an assigned, reserving appointment can be transferred.
            if ($locked->assigned_personnel_staff_profile_id === null
                || ! in_array($locked->status, [AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn], true)) {
                throw AppointmentStateException::notTransferable();
            }

            if ($locked->assigned_personnel_staff_profile_id === $target->id) {
                throw AppointmentStateException::sameTransferTarget();
            }

            $previousPersonnelUlid = $locked->assignedPersonnel?->ulid;

            /** @var MerchantBranch $branch */
            $branch = $locked->branch;
            /** @var Service $service */
            $service = $locked->service;
            /** @var Merchant $merchant */
            $merchant = $branch->merchant;

            $this->branchSchedule->ensure($branch, $locked->starts_at, $locked->ends_at);
            $this->scheduling->ensure($merchant, $branch, $service, $target, $locked->starts_at, $locked->ends_at);

            $sanitizedReason = $reason !== null && trim($reason) !== ''
                ? $this->redactor->redactString(mb_substr(trim($reason), 0, 500))
                : null;

            $this->mappingScheduleConflict(function () use ($locked, $target, $sanitizedReason): void {
                $locked->assigned_personnel_staff_profile_id = $target->id;
                $locked->transfer_reason = $sanitizedReason;
                $locked->save();
            });

            $this->audit->record(
                AuditEvent::AppointmentTransferred,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'appointment_id' => $locked->ulid,
                    'client_id' => $locked->client?->ulid,
                    'previous_personnel_id' => $previousPersonnelUlid,
                    'new_personnel_id' => $target->ulid,
                    'reason' => $sanitizedReason,
                ],
            );

            return $locked->refresh();
        });
    }
}
