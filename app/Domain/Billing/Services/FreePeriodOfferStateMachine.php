<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\FreePeriodOfferStatus;
use App\Domain\Billing\Exceptions\BillingStateException;

/**
 * Free-period-offer status-machine guard (Plan §53; Phase 20C). The single place that authorizes a
 * `free_period_offers.status` transition; the inventory lives on
 * {@see FreePeriodOfferStatus::allowedTransitions()} — note there is NO direct `draft → active`
 * transition (approval yields `scheduled`; activation is a separate step). Every transition has a
 * named action (or the lifecycle scheduler) and runs through here. An unlisted transition raises
 * {@see BillingStateException} → `422 invalid_state_transition`.
 */
final class FreePeriodOfferStateMachine
{
    public function canTransition(FreePeriodOfferStatus $from, FreePeriodOfferStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws BillingStateException
     */
    public function ensure(FreePeriodOfferStatus $from, FreePeriodOfferStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw BillingStateException::invalidTransition('free-period offer', $from->value, $to->value);
        }
    }
}
