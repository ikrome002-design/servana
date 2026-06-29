<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Services\AppointmentStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mark a confirmed appointment as a no-show (Plan §36, §25.2; Phase 16A).
 *
 * A distinct state, action, route, and audit event — NOT a cancellation and NOT
 * personnel unavailability. Authorized through the Front Office `appointment.cancel`
 * permission (the canonical catalogue defines no separate `appointment.no_show`
 * key). Permitted only from `confirmed`; sets `no_show_at` and releases the
 * personnel reservation via the status change.
 */
final class MarkAppointmentNoShow
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly AppointmentStateMachine $stateMachine,
    ) {}

    public function handle(Appointment $appointment, User $actor): Appointment
    {
        return DB::transaction(function () use ($appointment, $actor): Appointment {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('client');

            $this->stateMachine->ensure($locked->status, AppointmentStatus::NoShow);

            $locked->status = AppointmentStatus::NoShow;
            $locked->no_show_at = now();
            $locked->save();

            $this->audit->record(
                AuditEvent::AppointmentNoShow,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'appointment_id' => $locked->ulid,
                    'client_id' => $locked->client?->ulid,
                    'previous_state' => AppointmentStatus::Confirmed->value,
                    'new_state' => AppointmentStatus::NoShow->value,
                ],
            );

            return $locked->refresh();
        });
    }
}
