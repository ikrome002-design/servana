<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Normalized target-row type shared by promotional-discount and free-period-offer
 * targets (Plan §53; Phase 20C). Exactly one of `merchant_id` / `subscription_plan_id`
 * / `billing_mode` is set on a target row and must match this type (DB CHECK). Mirrored
 * across the PHP enum, the PostgreSQL CHECK on
 * `{promotional_discount_targets,free_period_offer_targets}.target_type`, factories,
 * request validation/OpenAPI/TS, and audit context. Parity guarded by
 * `Phase20CEnumParityTest`.
 */
enum PromotionTargetType: string
{
    case Merchant = 'merchant';
    case Plan = 'plan';
    case BillingMode = 'billing_mode';

    /**
     * All backing values, in canonical order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    /** Resolution precedence weight — higher wins (merchant > plan > billing_mode). */
    public function precedence(): int
    {
        return match ($this) {
            self::Merchant => 3,
            self::Plan => 2,
            self::BillingMode => 1,
        };
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::Merchant => 'Merchant',
            self::Plan => 'Plan',
            self::BillingMode => 'Billing mode',
        };
    }
}
