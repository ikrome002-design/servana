<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Scheduling\Concerns\MapsScheduleConflict;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Services\AppointmentBranchScheduleValidator;
use App\Domain\Scheduling\Services\PersonnelSchedulingValidator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Create a Front-Office appointment (Plan §36, §25.2; Phase 16A).
 *
 * The end time is snapshotted from the selected service's current
 * `duration_minutes` (a later service-duration change never mutates this row).
 * Branch operating-hours/calendar are validated for every appointment; when an
 * assigned personnel member is provided, the shared Phase 15B
 * {@see PersonnelSchedulingValidator} additionally gates eligibility +
 * availability and the appointment is born `confirmed` (the reservation). With no
 * personnel it is `scheduled`. The DB exclusion constraint is the final
 * double-booking authority (mapped to 409). Front Office authority is enforced
 * upstream; the merchant/branch/end-time/status/actor are derived here, never
 * from the request body.
 */
final class CreateAppointment
{
    use MapsScheduleConflict;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly AppointmentBranchScheduleValidator $branchSchedule,
        private readonly PersonnelSchedulingValidator $scheduling,
    ) {}

    public function handle(
        MerchantBranch $branch,
        User $actor,
        Client $client,
        Service $service,
        CarbonInterface $startsAt,
        ?StaffProfile $assigned = null,
        ?StaffProfile $preferred = null,
    ): Appointment {
        // Persist the absolute instant in the app timezone (UTC). The incoming
        // value carries its own offset; normalizing to UTC keeps Eloquent from
        // storing the wall-clock as-if-UTC. Business logic re-derives Africa/Nairobi.
        $start = CarbonImmutable::parse($startsAt)->utc();
        $end = $start->addMinutes($service->duration_minutes);

        // Branch open/calendar gate applies to every appointment (assigned or not).
        $this->branchSchedule->ensure($branch, $start, $end);

        $status = AppointmentStatus::Scheduled;

        if ($assigned !== null) {
            /** @var Merchant $merchant */
            $merchant = $branch->merchant;
            // Shared eligibility + availability gate (no duplication here).
            $this->scheduling->ensure($merchant, $branch, $service, $assigned, $start, $end);
            $status = AppointmentStatus::Confirmed;
        }

        return DB::transaction(function () use ($branch, $actor, $client, $service, $assigned, $preferred, $start, $end, $status): Appointment {
            $appointment = $this->mappingScheduleConflict(fn (): Appointment => Appointment::query()->create([
                'merchant_id' => $branch->merchant_id,
                'branch_id' => $branch->id,
                'client_id' => $client->id,
                'service_id' => $service->id,
                'preferred_personnel_staff_profile_id' => $preferred?->id,
                'assigned_personnel_staff_profile_id' => $assigned?->id,
                'starts_at' => $start,
                'ends_at' => $end,
                'status' => $status,
                'created_by' => $actor->id,
            ]));

            $this->audit->record(
                AuditEvent::AppointmentCreated,
                $actor,
                $branch->merchant_id,
                $branch->id,
                $appointment,
                [
                    'appointment_id' => $appointment->ulid,
                    'client_id' => $client->ulid,
                    'service_id' => $service->ulid,
                    'status' => $status->value,
                    'starts_at' => $start->toIso8601String(),
                    'ends_at' => $end->toIso8601String(),
                    'assigned_personnel_id' => $assigned?->ulid,
                    'preferred_personnel_id' => $preferred?->ulid,
                ],
            );

            return $appointment;
        });
    }
}
