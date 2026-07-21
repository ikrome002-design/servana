<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Hr\Models\StaffProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server-authoritative personnel OWN-SCOPE earnings read model (Plan §63; §H10). Every method takes an
 * EXPLICIT {@see StaffProfile} — the caller (an Increment-5 controller) resolves it from the
 * authenticated membership; a browser never chooses the subject. It reads only the authoritative
 * financial facts (`salary_ledger`, `commission_ledger`, `compensation_adjustments`,
 * `personnel_payout_items`) and never recomputes salary/commission; the compensation-terms view is
 * explanatory only. Money is integer minor units (ADR-005); currencies are grouped and NEVER combined.
 *
 * **Double-count avoidance (§6.4):** overview money totals derive from the SOURCE LEDGER facts only —
 * each ledger row is counted once by its own status (`<> paid` = outstanding, `= paid` = paid; a
 * reversed original and its exact-negative reversal both fall in the outstanding bucket and net to
 * zero, mirroring CompensationLiabilityReadModel). Payout items are shown as HISTORY, never re-summed
 * into the money totals.
 */
final class PersonnelEarningsReadModel
{
    /**
     * Tab visibility by compensation model (§6.5). Fails closed on conflicting active plans.
     *
     * @return array{model: string|null, has_current_plan: bool, conflicting: bool, salary_tab: bool, commission_tab: bool}
     */
    public function tabVisibility(StaffProfile $staff): array
    {
        $active = PersonnelCompensationPlan::query()
            ->where('staff_profile_id', $staff->id)
            ->where('status', CompensationPlanStatus::Active->value)
            ->get();

        if ($active->count() > 1) {
            // Conflicting current plans — do not guess; hide both tabs (fail closed).
            return ['model' => null, 'has_current_plan' => true, 'conflicting' => true, 'salary_tab' => false, 'commission_tab' => false];
        }

        if ($active->count() === 1) {
            $model = $active->firstOrFail()->compensation_model;

            return [
                'model' => $model->value,
                'has_current_plan' => true,
                'conflicting' => false,
                'salary_tab' => $model->requiresSalary(),
                'commission_tab' => $model->requiresCommissionRule(),
            ];
        }

        // No current plan: show a tab only where historical facts exist (paid earnings stay visible).
        return [
            'model' => null,
            'has_current_plan' => false,
            'conflicting' => false,
            'salary_tab' => SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id)->exists(),
            'commission_tab' => CommissionLedgerEntry::query()->where('staff_profile_id', $staff->id)->exists(),
        ];
    }

    /**
     * Per-currency earnings overview (outstanding vs paid vs net). Source-ledger view only.
     *
     * @return list<array<string, int|string>>
     */
    public function overview(StaffProfile $staff): array
    {
        $salaryUnpaid = $this->sumByCurrency(
            SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id)->where('status', '!=', 'paid'),
        );
        $salaryPaid = $this->sumByCurrency(
            SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id)->where('status', 'paid'),
        );
        $commissionUnpaid = $this->sumByCurrency(
            CommissionLedgerEntry::query()->where('staff_profile_id', $staff->id)->whereNotIn('status', ['paid', 'cancelled']),
        );
        $commissionPaid = $this->sumByCurrency(
            CommissionLedgerEntry::query()->where('staff_profile_id', $staff->id)->where('status', 'paid'),
        );

        $paidItemIds = PersonnelPayoutItem::query()
            ->where('staff_profile_id', $staff->id)->where('status', 'paid')->pluck('id')->all();

        $adjustmentPaid = $this->sumByCurrency(
            CompensationAdjustment::query()->where('staff_profile_id', $staff->id)->whereIn('payout_item_id', $paidItemIds),
        );
        $adjustmentUnpaid = $this->sumByCurrency(
            CompensationAdjustment::query()->where('staff_profile_id', $staff->id)
                ->where(fn (Builder $q) => $q->whereNull('payout_item_id')->orWhereNotIn('payout_item_id', $paidItemIds)),
        );

        $currencies = array_values(array_unique(array_merge(
            array_keys($salaryUnpaid), array_keys($salaryPaid),
            array_keys($commissionUnpaid), array_keys($commissionPaid),
            array_keys($adjustmentPaid), array_keys($adjustmentUnpaid),
        )));
        sort($currencies);

        $rows = [];
        foreach ($currencies as $currency) {
            $sUnpaid = $salaryUnpaid[$currency] ?? 0;
            $sPaid = $salaryPaid[$currency] ?? 0;
            $cUnpaid = $commissionUnpaid[$currency] ?? 0;
            $cPaid = $commissionPaid[$currency] ?? 0;
            $aUnpaid = $adjustmentUnpaid[$currency] ?? 0;
            $aPaid = $adjustmentPaid[$currency] ?? 0;

            $unpaid = $sUnpaid + $cUnpaid + $aUnpaid;
            $paid = $sPaid + $cPaid + $aPaid;

            $rows[] = [
                'currency' => $currency,
                'salary_unpaid_minor' => $sUnpaid,
                'salary_paid_minor' => $sPaid,
                'commission_unpaid_minor' => $cUnpaid,
                'commission_paid_minor' => $cPaid,
                'adjustment_unpaid_minor' => $aUnpaid,
                'adjustment_paid_minor' => $aPaid,
                'unpaid_minor' => $unpaid,
                'paid_minor' => $paid,
                'net_minor' => $unpaid + $paid,
            ];
        }

        return $rows;
    }

    /**
     * Own payout history (from the payout-item snapshots). Masked/shaped by the Increment-5 Resource;
     * this read model only bounds it to the personnel's own items.
     *
     * @return LengthAwarePaginator<int, PersonnelPayoutItem>
     */
    public function payoutHistory(StaffProfile $staff, int $perPage = 15): LengthAwarePaginator
    {
        return PersonnelPayoutItem::query()
            ->where('staff_profile_id', $staff->id)
            ->with('payoutRun')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Explanatory current compensation terms (NEVER a source of historical earnings; §9). Fails closed
     * on conflicting active plans.
     *
     * @return array<string, mixed>
     */
    public function compensationTerms(StaffProfile $staff): array
    {
        $active = PersonnelCompensationPlan::query()
            ->where('staff_profile_id', $staff->id)
            ->where('status', CompensationPlanStatus::Active->value)
            ->get();

        if ($active->count() > 1) {
            return ['has_current_plan' => true, 'conflicting' => true];
        }

        if ($active->count() === 0) {
            return ['has_current_plan' => false, 'conflicting' => false];
        }

        $plan = $active->firstOrFail();

        return [
            'has_current_plan' => true,
            'conflicting' => false,
            'compensation_model' => $plan->compensation_model->value,
            'salary_amount_minor' => $plan->salary_amount_minor,
            'salary_currency' => $plan->salary_currency,
            'salary_period' => $plan->salary_period?->value,
            'suspension_salary_policy' => $plan->suspension_salary_policy->value,
            'effective_from' => $plan->effective_from->toDateString(),
        ];
    }

    /**
     * Sum amount_minor grouped by currency for a bounded query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return array<string, int>
     */
    private function sumByCurrency(Builder $query): array
    {
        /** @var array<string, int> $out */
        $out = [];

        $rows = $query->toBase()
            ->selectRaw('currency, sum(amount_minor) as total')
            ->groupBy('currency')
            ->get();

        foreach ($rows as $row) {
            $out[(string) $row->currency] = (int) $row->total;
        }

        return $out;
    }
}
