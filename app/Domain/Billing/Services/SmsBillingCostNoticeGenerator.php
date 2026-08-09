<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\PlatformSmsBillingRule;
use App\Domain\Billing\Queries\ResolveEffectivePlatformBillingSettings;
use App\Domain\Billing\Queries\ResolveEffectiveSmsBillingRule;
use App\Enums\Currency;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Generate the merchant-facing SMS cost notice (COR-UI08-001 §9; Phase UI-08).
 *
 * THE NOTICE IS GENERATED, NEVER STORED. No cost-notice markup or free text is persisted anywhere,
 * so no unreviewed content can reach a merchant surface. Its inputs are exactly: unit cost,
 * currency, billable units (segments x recipients), the configured tax rate and the effective date.
 *
 * TAX IS DISCLOSED, NOT CHARGED. `sms_billing_entries` carries the shipped CHECK
 * `amount_minor = quantity * unit_cost_minor`, so folding tax into a charged amount would violate
 * it. The ex-tax amount is what is billed; the tax figure is a separately labelled estimate and is
 * absent entirely when no rate is configured (the launch state).
 *
 * Arithmetic is integer-only throughout (ADR-005) — the same basis and the same {@see Money}
 * multiplication Phase 21S already bills on. Basis-point tax uses integer division, which truncates
 * towards zero, so a disclosed estimate never overstates the charge.
 */
final class SmsBillingCostNoticeGenerator
{
    public function __construct(
        private readonly ResolveEffectiveSmsBillingRule $rules,
        private readonly ResolveEffectivePlatformBillingSettings $settings,
    ) {}

    /**
     * @return array{
     *     rule_id:string,
     *     effective_from:string,
     *     currency:string,
     *     recipient_count:int,
     *     segment_count:int,
     *     billable_units:int,
     *     unit_cost_minor:int,
     *     amount_minor:int,
     *     tax_basis_points:int|null,
     *     disclosed_tax_minor:int|null,
     *     disclosed_total_minor:int|null,
     *     notice:string
     * }
     */
    public function preview(int $recipientCount, int $segmentCount, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $rule = $this->rules->requireCurrent($asOf);

        return $this->forRule($rule, $recipientCount, $segmentCount, $asOf);
    }

    /**
     * @return array{
     *     rule_id:string,
     *     effective_from:string,
     *     currency:string,
     *     recipient_count:int,
     *     segment_count:int,
     *     billable_units:int,
     *     unit_cost_minor:int,
     *     amount_minor:int,
     *     tax_basis_points:int|null,
     *     disclosed_tax_minor:int|null,
     *     disclosed_total_minor:int|null,
     *     notice:string
     * }
     */
    public function forRule(
        PlatformSmsBillingRule $rule,
        int $recipientCount,
        int $segmentCount,
        ?CarbonImmutable $asOf = null,
    ): array {
        $asOf ??= CarbonImmutable::now();
        $currency = $this->currencyAt($asOf);

        $billableUnits = max($recipientCount, 0) * max($segmentCount, 0);
        $amount = Money::ofMinor($rule->unit_cost_minor, $currency)->multiply($billableUnits);

        $taxMinor = null;
        $totalMinor = null;

        if ($rule->tax_basis_points !== null) {
            // Integer basis points: (amount * bp) / 10000, truncating — never float.
            $taxMinor = intdiv($amount->minorUnits * $rule->tax_basis_points, 10_000);
            $totalMinor = $amount->minorUnits + $taxMinor;
        }

        return [
            'rule_id' => $rule->ulid,
            'effective_from' => $rule->effective_from->toIso8601String(),
            'currency' => $currency->value,
            'recipient_count' => max($recipientCount, 0),
            'segment_count' => max($segmentCount, 0),
            'billable_units' => $billableUnits,
            'unit_cost_minor' => $rule->unit_cost_minor,
            'amount_minor' => $amount->minorUnits,
            'tax_basis_points' => $rule->tax_basis_points,
            'disclosed_tax_minor' => $taxMinor,
            'disclosed_total_minor' => $totalMinor,
            'notice' => $this->composeNotice($amount, $billableUnits, $rule, $taxMinor, $currency),
        ];
    }

    /** Currency is the effective platform billing settings version's — never a second authority. */
    public function currencyAt(CarbonImmutable $asOf): Currency
    {
        $settings = $this->settings->current($asOf);

        return $settings === null
            ? Currency::KES
            : Currency::from($settings->currency);
    }

    private function composeNotice(
        Money $amount,
        int $billableUnits,
        PlatformSmsBillingRule $rule,
        ?int $taxMinor,
        Currency $currency,
    ): string {
        $unit = Money::ofMinor($rule->unit_cost_minor, $currency);

        $notice = sprintf(
            '%d billable SMS unit(s) at %s each — %s.',
            $billableUnits,
            $unit->format(),
            $amount->format(),
        );

        if ($taxMinor !== null && $rule->tax_basis_points !== null) {
            $notice .= sprintf(
                ' A tax or fee of %s bps (%s) is disclosed separately and is applied at invoicing; the charge recorded per campaign is the amount above.',
                number_format($rule->tax_basis_points),
                Money::ofMinor($taxMinor, $currency)->format(),
            );
        }

        return $notice.sprintf(' Pricing effective from %s.', $rule->effective_from->toDateString());
    }
}
