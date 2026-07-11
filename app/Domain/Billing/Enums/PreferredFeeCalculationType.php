<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Preferred-personnel fee calculation type (Plan §13.10; Phase 20A). Mirrors the DB
 * CHECK on `preferred_personnel_fee_rules.calculation_type`. `fixed_amount` requires a
 * fixed amount + currency (null basis points); `percentage` requires basis points (null
 * fixed amount/currency) — the DB value-shape CHECK is authoritative.
 */
enum PreferredFeeCalculationType: string
{
    case FixedAmount = 'fixed_amount';
    case Percentage = 'percentage';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
