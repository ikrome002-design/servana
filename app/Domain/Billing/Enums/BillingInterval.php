<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Canonical billing intervals (Plan §13.9, §47, §49; Phase 20A). The five — and
 * only five — canonical billing periods, used consistently across the PHP enum,
 * the PostgreSQL CHECK on `subscription_plan_prices.billing_interval` (and the 20B
 * subscription tables), API validation, OpenAPI, the generated TypeScript union,
 * price/plan-selection screens, and tests. Parity is guarded by
 * `BillingEnumParityTest`. No second interval vocabulary may exist.
 *
 * Phase 20A defines the vocabulary only; renewal/next-cycle date math (§49) is
 * Phase 20B and is deliberately NOT implemented here.
 */
enum BillingInterval: string
{
    case Weekly = 'weekly';
    case BiWeekly = 'bi_weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';

    /**
     * All backing values, in canonical order — the authoritative list for the DB
     * CHECK and every parity assertion.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $i): string => $i->value, self::cases());
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::BiWeekly => 'Bi-weekly',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Annual => 'Annual',
        };
    }
}
