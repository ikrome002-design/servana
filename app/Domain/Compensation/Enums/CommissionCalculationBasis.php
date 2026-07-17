<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * What a commission rule will be computed against (Plan §59; Scope §12.7 "Commission
 * basis"; Phase 20F). Mirrors the PostgreSQL CHECK on `commission_rules.calculation_basis`;
 * parity guarded by `Phase20FEnumParityTest`.
 *
 * Configuration only — Phase 20F stores the declared basis and computes nothing against
 * it. Phase 20G resolves the basis amount at Finance validation (Plan §61).
 */
enum CommissionCalculationBasis: string
{
    case ServicePrice = 'service_price';
    case InvoiceItemTotal = 'invoice_item_total';
    case PaidAmount = 'paid_amount';
    case NetAfterDiscount = 'net_after_discount';

    /**
     * All backing values, canonical order — authoritative for the DB CHECK and parity.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $b): string => $b->value, self::cases());
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::ServicePrice => 'Service price',
            self::InvoiceItemTotal => 'Invoice item total',
            self::PaidAmount => 'Paid amount',
            self::NetAfterDiscount => 'Net after discount',
        };
    }
}
