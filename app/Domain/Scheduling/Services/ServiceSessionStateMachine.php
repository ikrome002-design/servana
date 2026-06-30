<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Exceptions\ServiceSessionStateException;

/**
 * Service-session state-machine guard (Plan §25.1/§25.2; Phase 16C).
 *
 * THE single place that authorizes a service-session status transition. Domain
 * actions call {@see ensure()} before writing; the transition inventory lives on
 * {@see ServiceSessionStatus::allowedTransitions()}. There is no generic
 * `PATCH status` — every transition has a named action and runs through here.
 */
final class ServiceSessionStateMachine
{
    public function canTransition(ServiceSessionStatus $from, ServiceSessionStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * Assert a transition is legal or throw the canonical 422 envelope.
     *
     * @throws ServiceSessionStateException
     */
    public function ensure(ServiceSessionStatus $from, ServiceSessionStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw ServiceSessionStateException::invalidTransition($from, $to);
        }
    }
}
