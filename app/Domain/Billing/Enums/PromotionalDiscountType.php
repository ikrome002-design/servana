<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Promotional-discount type (Plan §53; Phase 20C). `percentage` values are basis
 * points (10000 = 100%); `fixed_amount` values are integer minor units in the
 * offer's currency. Mirrored across the PHP enum, the PostgreSQL CHECK on
 * `promotional_discounts.type`, factories, request validation/OpenAPI/TS, and audit
 * context. Parity is guarded by `Phase20CEnumParityTest`.
 */
enum PromotionalDiscountType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';

    /**
     * All backing values, in canonical order — authoritative for the DB CHECK and
     * every parity assertion.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::FixedAmount => 'Fixed amount',
        };
    }
}
