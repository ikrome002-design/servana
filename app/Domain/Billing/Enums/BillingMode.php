<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Canonical platform billing modes (Plan §2.1.9, §13.9, §50–§52; Phase 20A).
 * The single billing-mode vocabulary — mirrored across the PHP enum, the
 * PostgreSQL CHECK on `platform_billing_settings.billing_mode` (and the promotion/
 * fee tables), API validation, OpenAPI, the generated TypeScript union, seed/test
 * fixtures, screen options, and audit context. Parity is guarded by
 * `BillingEnumParityTest`. No second billing-mode vocabulary may exist.
 *
 * `fixed_amount` is the default launch mode (§50); the percentage and
 * fixed-plus-percentage modes are launch-capable but activated only when the
 * percentage platform-fee engine is configured (Phase 20E).
 */
enum BillingMode: string
{
    case FixedAmount = 'fixed_amount';
    case PercentageOnMerchantClientInvoice = 'percentage_on_merchant_client_invoice';
    case FixedAmountPlusPercentageOnMerchantClientInvoice = 'fixed_amount_plus_percentage_on_merchant_client_invoice';

    /** The default launch mode (Plan §50). */
    public static function default(): self
    {
        return self::FixedAmount;
    }

    /** Whether this mode has a percentage-on-merchant-client-invoice component. */
    public function hasPercentageComponent(): bool
    {
        return $this === self::PercentageOnMerchantClientInvoice
            || $this === self::FixedAmountPlusPercentageOnMerchantClientInvoice;
    }

    /** Whether this mode charges a flat plan price component. */
    public function hasFixedComponent(): bool
    {
        return $this === self::FixedAmount
            || $this === self::FixedAmountPlusPercentageOnMerchantClientInvoice;
    }

    /**
     * All backing values, in canonical order — the authoritative list for the DB
     * CHECK and every parity assertion.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::FixedAmount => 'Fixed amount',
            self::PercentageOnMerchantClientInvoice => 'Percentage on merchant-client invoice',
            self::FixedAmountPlusPercentageOnMerchantClientInvoice => 'Fixed amount plus percentage on merchant-client invoice',
        };
    }
}
