<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\PlatformFeeDisputeStatus;
use App\Domain\Billing\Exceptions\BillingStateException;

/**
 * Platform-fee dispute status-machine guard (Plan §13.10 [Correction 3]; Phase 20E). The single place
 * that authorizes a `platform_fee_disputes.status` transition; the inventory lives on
 * {@see PlatformFeeDisputeStatus::allowedTransitions()}. Every transition has a named action and runs
 * through here; an unlisted transition raises {@see BillingStateException} → `422
 * invalid_state_transition`. There is no `escalated` state. See
 * docs/architecture/state-machines/platform-fee-dispute.md.
 */
final class PlatformFeeDisputeStateMachine
{
    public function canTransition(PlatformFeeDisputeStatus $from, PlatformFeeDisputeStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws BillingStateException
     */
    public function ensure(PlatformFeeDisputeStatus $from, PlatformFeeDisputeStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw BillingStateException::invalidTransition('platform fee dispute', $from->value, $to->value);
        }
    }
}
