<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Platform SMS charge reconciliation (COR-UI08-001 §9; Phase UI-08).
 *
 * Answers three questions the Super Administrator actually needs:
 *   1. Where does every charge currently stand? (status rollup)
 *   2. Which billable charges have not yet reached a subscription invoice line? (mapping state)
 *   3. Is usage inside the configured warning and anomaly thresholds this month?
 *
 * Read-only. It never writes, never re-prices and never touches a snapshot: `sms_billing_entries`
 * is frozen by `sms_billing_entries_guard`, and the effective pricing rule is resolved for
 * DISPLAY only. Ex-tax and disclosed-inclusive totals are reported under separate labels so a
 * disclosed tax rate can never be mistaken for a charged amount.
 *
 * Aggregates only — no recipient, phone number or message body is reachable from here.
 */
final class SmsBillingChargeReconciliationProjection
{
    public function __construct(
        private readonly ResolveEffectiveSmsBillingRule $rules,
        private readonly SmsBillingUsageProjection $usage,
    ) {}

    /**
     * @return array{
     *     as_of:string,
     *     status_rollup:list<array{status:string,entry_count:int,amount_minor:int}>,
     *     invoice_mapping:array{linked_count:int,linked_amount_minor:int,unlinked_count:int,unlinked_amount_minor:int},
     *     thresholds:array{
     *         billable_units_this_month:int,
     *         billable_units_previous_month:int,
     *         warning_threshold_units:int|null,
     *         warning_state:string,
     *         anomaly_threshold_basis_points:int|null,
     *         growth_basis_points:int|null,
     *         anomaly_state:string
     *     },
     *     disclosed_tax:array{tax_basis_points:int|null,disclosed_tax_minor:int|null,disclosed_total_minor:int|null}
     * }
     */
    public function summary(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $rollup = $this->statusRollup();
        $rule = $this->rules->at($asOf);

        $thisMonth = $this->usage->billableUnitsForMonth($asOf);
        $previousMonth = $this->usage->billableUnitsForMonth($asOf->subMonth());

        $chargedMinor = 0;
        foreach ($rollup as $row) {
            if (in_array($row['status'], ['billable', 'invoiced'], true)) {
                $chargedMinor += $row['amount_minor'];
            }
        }

        $taxBasisPoints = $rule?->tax_basis_points;
        $disclosedTax = $taxBasisPoints === null
            ? null
            : intdiv($chargedMinor * $taxBasisPoints, 10_000);

        return [
            'as_of' => $asOf->toIso8601String(),
            'status_rollup' => $rollup,
            'invoice_mapping' => $this->invoiceMapping(),
            'thresholds' => [
                'billable_units_this_month' => $thisMonth,
                'billable_units_previous_month' => $previousMonth,
                'warning_threshold_units' => $rule?->usage_warning_threshold_units,
                'warning_state' => $this->warningState($thisMonth, $rule?->usage_warning_threshold_units),
                'anomaly_threshold_basis_points' => $rule?->usage_anomaly_threshold_basis_points,
                'growth_basis_points' => $this->growthBasisPoints($thisMonth, $previousMonth),
                'anomaly_state' => $this->anomalyState(
                    $this->growthBasisPoints($thisMonth, $previousMonth),
                    $rule?->usage_anomaly_threshold_basis_points,
                ),
            ],
            'disclosed_tax' => [
                'tax_basis_points' => $taxBasisPoints,
                'disclosed_tax_minor' => $disclosedTax,
                'disclosed_total_minor' => $disclosedTax === null ? null : $chargedMinor + $disclosedTax,
            ],
        ];
    }

    /**
     * The query builder, not the Eloquent model: an aggregate row is not an SmsBillingEntry, and
     * the model's `status` enum cast would fight the raw grouped value rather than help.
     *
     * @return list<array{status:string,entry_count:int,amount_minor:int}>
     */
    private function statusRollup(): array
    {
        $rows = DB::table('sms_billing_entries')
            ->select('status')
            ->selectRaw('count(*) as entry_count')
            ->selectRaw('coalesce(sum(amount_minor), 0) as amount_minor')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $rollup = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $rollup[] = [
                'status' => (string) ($values['status'] ?? ''),
                'entry_count' => (int) ($values['entry_count'] ?? 0),
                'amount_minor' => (int) ($values['amount_minor'] ?? 0),
            ];
        }

        return $rollup;
    }

    /**
     * @return array{linked_count:int,linked_amount_minor:int,unlinked_count:int,unlinked_amount_minor:int}
     */
    private function invoiceMapping(): array
    {
        $row = DB::table('sms_billing_entries')
            ->whereIn('status', ['billable', 'invoiced'])
            ->selectRaw('count(*) filter (where billing_invoice_line_id is not null) as linked_count')
            ->selectRaw('coalesce(sum(amount_minor) filter (where billing_invoice_line_id is not null), 0) as linked_amount_minor')
            ->selectRaw('count(*) filter (where billing_invoice_line_id is null) as unlinked_count')
            ->selectRaw('coalesce(sum(amount_minor) filter (where billing_invoice_line_id is null), 0) as unlinked_amount_minor')
            ->first();

        /** @var array<string, mixed> $values */
        $values = $row === null ? [] : (array) $row;

        return [
            'linked_count' => (int) ($values['linked_count'] ?? 0),
            'linked_amount_minor' => (int) ($values['linked_amount_minor'] ?? 0),
            'unlinked_count' => (int) ($values['unlinked_count'] ?? 0),
            'unlinked_amount_minor' => (int) ($values['unlinked_amount_minor'] ?? 0),
        ];
    }

    /** `not_configured` is a truthful state — it never masquerades as `ok`. */
    private function warningState(int $units, ?int $threshold): string
    {
        if ($threshold === null) {
            return 'not_configured';
        }

        return $units >= $threshold ? 'warning' : 'ok';
    }

    /**
     * Month-on-month growth in basis points, integer-only. Null when there is no previous month to
     * compare against — a first month of usage is not an anomaly, and dividing by zero to claim one
     * would be worse than saying nothing.
     */
    private function growthBasisPoints(int $thisMonth, int $previousMonth): ?int
    {
        if ($previousMonth <= 0) {
            return null;
        }

        return intdiv(($thisMonth - $previousMonth) * 10_000, $previousMonth);
    }

    private function anomalyState(?int $growthBasisPoints, ?int $threshold): string
    {
        if ($threshold === null) {
            return 'not_configured';
        }

        if ($growthBasisPoints === null) {
            return 'insufficient_history';
        }

        return $growthBasisPoints >= $threshold ? 'anomaly' : 'ok';
    }
}
