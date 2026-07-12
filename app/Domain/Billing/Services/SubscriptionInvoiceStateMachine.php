<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Exceptions\BillingStateException;

/**
 * Subscription-invoice status-machine guard (Plan §25.4, §49; Phase 20B). The single place that
 * authorizes a `subscription_invoices.status` transition; the inventory lives on
 * {@see SubscriptionInvoiceStatus::allowedTransitions()}. There is no generic status route — every
 * transition has a named action. An unlisted transition raises {@see BillingStateException} → `422
 * invalid_state_transition`. Cancellation terminology is `void` only (never `cancelled`).
 */
final class SubscriptionInvoiceStateMachine
{
    public function canTransition(SubscriptionInvoiceStatus $from, SubscriptionInvoiceStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws BillingStateException
     */
    public function ensure(SubscriptionInvoiceStatus $from, SubscriptionInvoiceStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw BillingStateException::invalidTransition('subscription invoice', $from->value, $to->value);
        }
    }
}
