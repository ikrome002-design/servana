<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\PromotionStatus;
use App\Domain\Billing\Exceptions\BillingStateException;

/**
 * Promotional-discount status-machine guard (Plan §53; Phase 20C). The single place that authorizes a
 * `promotional_discounts.status` transition; the inventory lives on
 * {@see PromotionStatus::allowedTransitions()}. There is NO generic status route or generic status
 * action — every transition has a named action (or the lifecycle scheduler) and runs through here. An
 * unlisted transition raises {@see BillingStateException} → `422 invalid_state_transition`.
 */
final class PromotionalDiscountStateMachine
{
    public function canTransition(PromotionStatus $from, PromotionStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws BillingStateException
     */
    public function ensure(PromotionStatus $from, PromotionStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw BillingStateException::invalidTransition('promotional discount', $from->value, $to->value);
        }
    }
}
