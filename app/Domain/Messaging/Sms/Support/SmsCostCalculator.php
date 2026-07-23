<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Support;

use App\Enums\Currency;
use App\Support\Money;
use RuntimeException;

/**
 * Server-authoritative SMS cost arithmetic (Plan §64 "estimated KES cost"; ADR-005; Phase 21S).
 *
 * The formula is deliberately trivial and integer-only:
 *
 *     amount_minor = recipients × segments × unit_cost_minor
 *
 * charged per SEGMENT per RECIPIENT. There is no float anywhere in the path: the unit price is
 * configured in minor units, the multiplication runs through {@see Money::multiply()} (which
 * detects 64-bit overflow), and `sms_billing_entries` re-checks `amount_minor = quantity *
 * unit_cost_minor` in the database.
 *
 * The frontend NEVER computes a cost — it displays what the preview endpoint returned. Both the
 * preview and the confirm path call this, so a tampered client value can never change what is
 * billed.
 *
 * The unit price itself is a configured placeholder carried by REM-SMS-002 (no SMS provider tariff
 * is pinned by the Plan). A negative price is a configuration error and fails closed here rather
 * than producing a negative charge.
 */
final class SmsCostCalculator
{
    /** Billable quantity: one unit per segment per recipient. */
    public function quantity(int $recipientCount, int $segmentCount): int
    {
        if ($recipientCount < 0 || $segmentCount < 0) {
            throw new RuntimeException('SMS quantity cannot be derived from a negative recipient or segment count.');
        }

        return $recipientCount * $segmentCount;
    }

    public function unitCostMinor(): int
    {
        $unitCost = (int) config('sms.pricing.unit_cost_minor');

        if ($unitCost < 0) {
            throw new RuntimeException('sms.pricing.unit_cost_minor must not be negative.');
        }

        return $unitCost;
    }

    public function currency(): Currency
    {
        return Currency::from((string) config('sms.pricing.currency', Currency::KES->value));
    }

    /** Total cost for a campaign of `$recipientCount` recipients at `$segmentCount` segments each. */
    public function total(int $recipientCount, int $segmentCount): Money
    {
        return Money::ofMinor($this->unitCostMinor(), $this->currency())
            ->multiply($this->quantity($recipientCount, $segmentCount));
    }

    /** Total in integer minor units (what the campaign/billing-entry columns store). */
    public function totalMinor(int $recipientCount, int $segmentCount): int
    {
        return $this->total($recipientCount, $segmentCount)->minorUnits;
    }
}
