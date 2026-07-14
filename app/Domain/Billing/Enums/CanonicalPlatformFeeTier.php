<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

use App\Domain\Merchants\Enums\ServiceFeeTier;

/**
 * Canonical percentage platform-fee tier (Plan §13.10, Scope §6.3; Phase 20E).
 *
 * The shipped merchant seam (`merchants.service_fee_tier` / {@see ServiceFeeTier}) uses
 * `split_tier`; the canonical platform-fee vocabulary uses `shared` (Scope §1050: `shared`
 * ≡ the previously-named "Split"). This enum is the ONE centralized mapping — controllers,
 * resources, frontend, and tests must not duplicate `split_tier → shared`. Mirrors the
 * PostgreSQL CHECKs on `platform_fee_configurations.tier_behavior` and
 * `platform_fee_ledger_entries.service_fee_tier_snapshot`. Parity: `Phase20EEnumParityTest`.
 */
enum CanonicalPlatformFeeTier: string
{
    case CustomerCentric = 'customer_centric';
    case Shared = 'shared';
    case BusinessCentric = 'business_centric';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    /**
     * The single authoritative mapping from the shipped merchant tier seam to the canonical
     * platform-fee tier. `split_tier → shared`; the other two are identity.
     */
    public static function fromMerchantTier(ServiceFeeTier $tier): self
    {
        return match ($tier) {
            ServiceFeeTier::CustomerCentric => self::CustomerCentric,
            ServiceFeeTier::SplitTier => self::Shared,
            ServiceFeeTier::BusinessCentric => self::BusinessCentric,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CustomerCentric => 'Customer centric',
            self::Shared => 'Shared',
            self::BusinessCentric => 'Business centric',
        };
    }
}
