<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Exceptions\AppointmentStateException;

/**
 * Appointment state-machine guard (Plan §25.1/§25.2; Phase 16A).
 *
 * THE single place that authorizes an appointment status transition. Domain
 * actions call {@see ensure()} before writing; the transition inventory lives on
 * {@see AppointmentStatus::allowedTransitions()}. There is no generic
 * `PATCH status` — every transition has a named action and runs through here.
 */
final class AppointmentStateMachine
{
    public function canTransition(AppointmentStatus $from, AppointmentStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * Assert a transition is legal or throw the canonical 422 envelope.
     *
     * @throws AppointmentStateException
     */
    public function ensure(AppointmentStatus $from, AppointmentStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw AppointmentStateException::invalidTransition($from, $to);
        }
    }
}
