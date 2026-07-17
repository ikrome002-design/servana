<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Services\CompensationBusinessDate;
use App\Domain\Compensation\ValueObjects\CompensationImpactPreview;

/**
 * Build the deterministic impact preview for a compensation plan (Plan §59 "impact preview";
 * Phase 20F, F8). Required before a BACKDATED plan may be approved.
 *
 * **Read-only and side-effect free.** It summarizes the CONFIGURATION the plan will put in force —
 * the subject, branch, window, model, salary terms, commission-rule terms, preferred-personnel-fee
 * inclusion, and the incumbent it would supersede. It recalculates, reprices, and creates NOTHING:
 * no earned salary, no earned commission, no payout amount, no arrears liability, no Wallet
 * settlement (Plan §61; Scope §12.7 3B — a backdated correction of already-earned commission is a
 * **Phase 20G** adjustment, not a 20F edit).
 */
final class BuildCompensationPlanImpactPreview
{
    public function __construct(
        private readonly CompensationBusinessDate $businessDate,
        private readonly ResolveEffectiveCompensationPlan $resolvePlan,
    ) {}

    public function handle(PersonnelCompensationPlan $plan): CompensationImpactPreview
    {
        $plan->loadMissing(['commissionRule', 'branch', 'staffProfile']);

        $effectiveFrom = $this->businessDate->normalize((string) $plan->effective_from);

        // The incumbent this plan would supersede, if any — resolved as of the new window's start,
        // not today, so a backdated change reports what it actually displaces.
        $incumbent = $plan->staffProfile === null
            ? null
            : $this->resolvePlan->handle($plan->staffProfile, $plan->branch_id, $effectiveFrom);

        $rule = $plan->commissionRule;

        return new CompensationImpactPreview(
            staffProfileUlid: (string) $plan->staffProfile?->ulid,
            branchUlid: (string) $plan->branch?->ulid,
            planUlid: $plan->ulid,
            supersedesPlanUlid: $incumbent?->ulid,
            compensationModel: $plan->compensation_model->value,
            salaryAmountMinor: $plan->salary_amount_minor,
            salaryCurrency: $plan->salary_currency,
            salaryPeriod: $plan->salary_period?->value,
            commissionRuleUlid: $rule?->ulid,
            commissionCalculationType: $rule?->calculation_type->value,
            commissionCalculationBasis: $rule?->calculation_basis->value,
            commissionPercentageBasisPoints: $rule?->percentage_basis_points,
            commissionFixedAmountMinor: $rule?->fixed_amount_minor,
            commissionCurrency: $rule?->currency,
            appliesToPreferredPersonnelFee: $rule instanceof CommissionRule && $rule->applies_to_preferred_personnel_fee,
            effectiveFrom: $effectiveFrom,
            effectiveTo: $plan->effective_to === null ? null : $this->businessDate->normalize((string) $plan->effective_to),
            isBackdated: $this->businessDate->isBackdated($effectiveFrom),
            businessDate: $this->businessDate->today(),
        );
    }
}
