<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\PreferredPersonnelFeeRuleStatus;
use App\Domain\Billing\Exceptions\BillingStateException;

/**
 * Preferred-personnel-fee-rule state-machine guard (Plan §13.10, §47; Phase 20A). The single
 * place that authorizes a `preferred_personnel_fee_rules.status` transition; the inventory lives
 * on {@see PreferredPersonnelFeeRuleStatus::allowedTransitions()}. Each transition (approve/
 * activate, supersede, cancel, expire) has a named action and runs through here; there is no
 * generic status route. Active monetary terms are immutable — a change supersedes with a new
 * version (see {@see BillingStateException::activeTermsImmutable()}).
 */
final class PreferredPersonnelFeeRuleStateMachine
{
    public function canTransition(PreferredPersonnelFeeRuleStatus $from, PreferredPersonnelFeeRuleStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws BillingStateException
     */
    public function ensure(PreferredPersonnelFeeRuleStatus $from, PreferredPersonnelFeeRuleStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw BillingStateException::invalidTransition('preferred-personnel fee rule', $from->value, $to->value);
        }
    }
}
