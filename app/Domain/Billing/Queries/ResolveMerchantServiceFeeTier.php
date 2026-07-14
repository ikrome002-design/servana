<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Exceptions\PlatformFeeException;
use App\Domain\Merchants\Enums\ServiceFeeTier;

/**
 * Resolves the canonical percentage-fee tier for a merchant (Plan §13.10, Scope §6.3; Phase 20E).
 *
 * The shipped merchant seam (`merchants.service_fee_tier` / {@see ServiceFeeTier}) uses `split_tier`;
 * the canonical vocabulary uses `shared`. This resolver is the ONLY place that consumes the merchant
 * seam for fee purposes — it maps via {@see CanonicalPlatformFeeTier::fromMerchantTier()} (the single
 * mapping) and applies precedence:
 *
 *   merchant's own service_fee_tier (mapped) → else the effective configuration's tier_behavior default
 *
 * A percentage-bearing mode with neither a merchant tier nor a configured default FAILS CLOSED
 * ({@see PlatformFeeException::missingTier()}) — a merchant is never silently defaulted into a
 * liability-changing tier. Pure and read-only.
 */
final class ResolveMerchantServiceFeeTier
{
    public function resolve(?ServiceFeeTier $merchantTier, ?CanonicalPlatformFeeTier $configuredDefault): CanonicalPlatformFeeTier
    {
        if ($merchantTier !== null) {
            return CanonicalPlatformFeeTier::fromMerchantTier($merchantTier);
        }

        if ($configuredDefault !== null) {
            return $configuredDefault;
        }

        throw PlatformFeeException::missingTier();
    }
}
