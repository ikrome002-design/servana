<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\PlatformFeeConfigurationStatus;
use App\Domain\Billing\Exceptions\BillingStateException;

/**
 * Platform-fee configuration status-machine guard (Plan §13.10; Phase 20E). The single place that
 * authorizes a `platform_fee_configurations.status` transition; the inventory lives on
 * {@see PlatformFeeConfigurationStatus::allowedTransitions()}. There is NO generic status route or
 * generic status action — every transition has a named action and runs through here. An unlisted
 * transition raises {@see BillingStateException} → `422 invalid_state_transition`. See
 * docs/architecture/state-machines/platform-fee-configuration.md.
 */
final class PlatformFeeConfigurationStateMachine
{
    public function canTransition(PlatformFeeConfigurationStatus $from, PlatformFeeConfigurationStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws BillingStateException
     */
    public function ensure(PlatformFeeConfigurationStatus $from, PlatformFeeConfigurationStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw BillingStateException::invalidTransition('platform fee configuration', $from->value, $to->value);
        }
    }
}
