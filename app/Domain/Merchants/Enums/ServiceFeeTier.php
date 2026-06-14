<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Enums;

/**
 * Merchant service fee tier (Scope §3.2 "Merchant Service Fee Tier Selection").
 *
 * Mirrors the merchants.service_fee_tier DB CHECK. The tier affects ONLY how the
 * Citrus platform fee is distributed onto the merchant-client invoice; it does
 * NOT reduce the merchant's platform-fee liability. The actual pricing maths
 * lives in the Invoicing / Billing phases (17/20) — Phase 6 only persists the
 * selected tier (required before setup completion).
 */
enum ServiceFeeTier: string
{
    case CustomerCentric = 'customer_centric';
    case SplitTier = 'split_tier';
    case BusinessCentric = 'business_centric';

    /** Short human label for onboarding UI / proof. */
    public function label(): string
    {
        return match ($this) {
            self::CustomerCentric => 'Customer Centric',
            self::SplitTier => 'Split Tier',
            self::BusinessCentric => 'Business Centric',
        };
    }
}
