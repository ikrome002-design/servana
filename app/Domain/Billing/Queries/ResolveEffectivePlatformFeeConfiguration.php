<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\PlatformFeeConfigurationStatus;
use App\Domain\Billing\Exceptions\PlatformFeeException;
use App\Domain\Billing\Models\PlatformFeeConfiguration;
use Carbon\CarbonInterface;

/**
 * Resolves the effective percentage platform-fee configuration for a (billing_mode, currency) on a date
 * (Plan §13.10, §51; Phase 20E). The effective config is the `active` one whose half-open effective
 * range [effective_from, effective_to) contains the date, for the matching mode + uppercase currency.
 *
 *   - fixed_amount mode → no configuration is resolved (the engine is inert); returns null via {@see find}.
 *   - a percentage-bearing mode with no effective active config → FAILS CLOSED
 *     ({@see PlatformFeeException::missingConfiguration()}) via {@see require}.
 *
 * Pure, read-only. The caller snapshots the resolved config onto the invoice at finalization; a later
 * config change never recalculates an issued invoice.
 */
final class ResolveEffectivePlatformFeeConfiguration
{
    public function find(BillingMode $mode, string $currency, CarbonInterface $onDate): ?PlatformFeeConfiguration
    {
        if (! $mode->hasPercentageComponent()) {
            return null;
        }

        $date = $onDate->toDateString();

        return PlatformFeeConfiguration::query()
            ->where('status', PlatformFeeConfigurationStatus::Active->value)
            ->where('billing_mode', $mode->value)
            ->where('currency', strtoupper($currency))
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    public function require(BillingMode $mode, string $currency, CarbonInterface $onDate): PlatformFeeConfiguration
    {
        $config = $this->find($mode, $currency, $onDate);

        if ($config === null) {
            throw PlatformFeeException::missingConfiguration($mode->value, strtoupper($currency), $onDate->toDateString());
        }

        return $config;
    }
}
