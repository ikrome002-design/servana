<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\SubscriptionPlanStatus;
use App\Domain\Billing\Exceptions\BillingStateException;

/**
 * Subscription-plan status-machine guard (Plan §13.9, §47; Phase 20A). The single place
 * that authorizes a `subscription_plans.status` transition; the inventory lives on
 * {@see SubscriptionPlanStatus::allowedTransitions()}. There is no generic status route —
 * every transition (retire) has a named action and runs through here.
 */
final class SubscriptionPlanStateMachine
{
    public function canTransition(SubscriptionPlanStatus $from, SubscriptionPlanStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws BillingStateException
     */
    public function ensure(SubscriptionPlanStatus $from, SubscriptionPlanStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw BillingStateException::invalidTransition('subscription plan', $from->value, $to->value);
        }
    }
}
