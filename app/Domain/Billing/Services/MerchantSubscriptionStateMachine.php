<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Exceptions\BillingStateException;

/**
 * Merchant-subscription status-machine guard (Plan §25.2/§25.4; Phase 20B). The single place that
 * authorizes a `merchant_subscriptions.status` transition; the inventory lives on
 * {@see MerchantSubscriptionStatus::allowedTransitions()}. There is NO generic status route or
 * generic status action — every transition has a named action and runs through here. An unlisted
 * transition raises {@see BillingStateException} → `422 invalid_state_transition`.
 */
final class MerchantSubscriptionStateMachine
{
    public function canTransition(MerchantSubscriptionStatus $from, MerchantSubscriptionStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws BillingStateException
     */
    public function ensure(MerchantSubscriptionStatus $from, MerchantSubscriptionStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw BillingStateException::invalidTransition('merchant subscription', $from->value, $to->value);
        }
    }
}
