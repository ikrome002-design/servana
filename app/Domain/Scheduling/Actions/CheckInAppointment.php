<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Exceptions\AppointmentStateException;
use App\Domain\Scheduling\Exceptions\BranchDayNotOpenException;
use App\Domain\Scheduling\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Record a client check-in (Plan §36, §25.2; Phase 16A). Permitted only from
 * `confirmed`. The branch business day must be operationally open
 * (`open`/`paused`/`reopened`) for today's `Africa/Nairobi` business date. It sets
 * `checked_in_at` and the `checked_in` status — it does NOT create a queue entry
 * (16B) or a service session (16C); appointment-to-queue conversion is Phase 16B.
 */
final class CheckInAppointment
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Appointment $appointment, User $actor): Appointment
    {
        return DB::transaction(function () use ($appointment, $actor): Appointment {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('client');

            // confirmed → checked_in only.
            if (! $locked->status->canTransitionTo(AppointmentStatus::CheckedIn)) {
                throw AppointmentStateException::invalidTransition($locked->status, AppointmentStatus::CheckedIn);
            }

            if (! $this->branchDayIsOpen($locked->branch_id)) {
                throw BranchDayNotOpenException::make();
            }

            $locked->status = AppointmentStatus::CheckedIn;
            $locked->checked_in_at = now();
            $locked->save();

            $this->audit->record(
                AuditEvent::AppointmentCheckedIn,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'appointment_id' => $locked->ulid,
                    'client_id' => $locked->client?->ulid,
                    'previous_state' => AppointmentStatus::Confirmed->value,
                    'new_state' => AppointmentStatus::CheckedIn->value,
                ],
            );

            return $locked->refresh();
        });
    }

    private function branchDayIsOpen(int $branchId): bool
    {
        $businessDate = CarbonImmutable::now('Africa/Nairobi')->toDateString();

        $day = BranchDayRecord::query()
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->first();

        return $day !== null && $day->status->isLive();
    }
}
