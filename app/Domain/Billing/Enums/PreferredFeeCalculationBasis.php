<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Preferred-personnel fee calculation basis (Plan §13.10; Phase 20A). Mirrors the DB
 * CHECK on `preferred_personnel_fee_rules.calculation_basis` — the service-item amount a
 * percentage rule multiplies. At the current invoice-item model (quantity 1, no per-item
 * tax/discount) net == gross == the service price; the distinction becomes material when
 * per-item tax/discount is introduced (later phase).
 */
enum PreferredFeeCalculationBasis: string
{
    case ServiceItemNetAmount = 'service_item_net_amount';
    case ServiceItemGrossAmount = 'service_item_gross_amount';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $b): string => $b->value, self::cases());
    }
}
