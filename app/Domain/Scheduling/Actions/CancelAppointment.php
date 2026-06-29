<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Exceptions\AppointmentStateException;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Services\AppointmentStateMachine;
use App\Models\User;
use App\Support\Redaction\Redactor;
use Illuminate\Support\Facades\DB;

/**
 * Cancel an appointment (Plan §36, §25.2; Phase 16A). Before check-in this is a
 * plain `cancelled` (reason optional); after check-in it is
 * `cancelled_with_reason` and a non-empty reason is REQUIRED. The personnel
 * reservation is released by the status change (never a hard delete). The reason
 * is sanitised before it reaches the audit record.
 */
final class CancelAppointment
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly AppointmentStateMachine $stateMachine,
        private readonly Redactor $redactor,
    ) {}

    public function handle(Appointment $appointment, User $actor, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $actor, $reason): Appointment {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('client');

            $from = $locked->status;
            $target = $from === AppointmentStatus::CheckedIn
                ? AppointmentStatus::CancelledWithReason
                : AppointmentStatus::Cancelled;

            $this->stateMachine->ensure($from, $target);

            $sanitizedReason = $reason !== null && trim($reason) !== ''
                ? $this->redactor->redactString(mb_substr(trim($reason), 0, 500))
                : null;

            if ($target === AppointmentStatus::CancelledWithReason && $sanitizedReason === null) {
                throw AppointmentStateException::reasonRequired();
            }

            $locked->status = $target;
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $sanitizedReason;
            $locked->save();

            $this->audit->record(
                AuditEvent::AppointmentCancelled,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'appointment_id' => $locked->ulid,
                    'client_id' => $locked->client?->ulid,
                    'previous_state' => $from->value,
                    'new_state' => $target->value,
                    'reason' => $sanitizedReason,
                ],
            );

            return $locked->refresh();
        });
    }
}
