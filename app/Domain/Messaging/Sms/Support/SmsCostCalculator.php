<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Support;

use App\Domain\Billing\Queries\ResolveEffectivePlatformBillingSettings;
use App\Domain\Billing\Queries\ResolveEffectiveSmsBillingRule;
use App\Enums\Currency;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
 * PRICING AUTHORITY (COR-UI08-001; Phase UI-08). The unit price is no longer deployment
 * configuration. It is the effective row of `platform_sms_billing_rules` — a versioned,
 * effective-dated, audited, MFA + step-up governed series resolved at the usage instant. A
 * scheduled rule ALWAYS wins: config is consulted only when the series is empty, which is the
 * bootstrap state of a database whose platform administrator has not been seeded yet. That is the
 * same value the migration's genesis rule is created from, so the two can never disagree about a
 * price anyone actually scheduled. `SmsBillingRuleAlwaysWinsOverConfigTest` pins this.
 *
 * A negative price is a configuration error and fails closed here rather than producing a negative
 * charge.
 */
final class SmsCostCalculator
{
    public function __construct(
        private readonly ?ResolveEffectiveSmsBillingRule $rules = null,
        private readonly ?ResolveEffectivePlatformBillingSettings $settings = null,
    ) {}

    /** Billable quantity: one unit per segment per recipient. */
    public function quantity(int $recipientCount, int $segmentCount): int
    {
        if ($recipientCount < 0 || $segmentCount < 0) {
            throw new RuntimeException('SMS quantity cannot be derived from a negative recipient or segment count.');
        }

        return $recipientCount * $segmentCount;
    }

    public function unitCostMinor(?CarbonImmutable $asOf = null): int
    {
        $rule = $this->rules?->at($asOf);

        $unitCost = $rule !== null
            ? $rule->unit_cost_minor
            : (int) config('sms.pricing.unit_cost_minor');

        if ($unitCost < 0) {
            throw new RuntimeException('The effective SMS unit cost must not be negative.');
        }

        return $unitCost;
    }

    /**
     * Currency has exactly ONE authority: the effective platform billing settings version. Before
     * Phase UI-08 this read `sms.pricing.currency` while the platform surface read the settings
     * version, so the two could disagree and the SMS page would have misreported the currency of a
     * charge. Config remains only for the bootstrap case where no settings version exists yet.
     */
    public function currency(?CarbonImmutable $asOf = null): Currency
    {
        $settings = $this->settings?->current($asOf);

        if ($settings !== null) {
            return Currency::from($settings->currency);
        }

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
