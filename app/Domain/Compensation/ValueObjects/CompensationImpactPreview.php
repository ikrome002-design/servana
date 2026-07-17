<?php

declare(strict_types=1);

namespace App\Domain\Compensation\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * A deterministic, side-effect-free summary of what a compensation change WILL configure
 * (Plan §59 "impact preview"; Phase 20F, F8). Required before a BACKDATED plan may be approved.
 *
 * **Configuration only.** It reports the configured terms and the affected window. It computes NO
 * earned salary, NO earned commission, NO payout amount, NO arrears liability, and NO Wallet
 * settlement — Plan §61 keeps recalculation of already-earned commission out of Phase 20F entirely
 * (Scope §12.7 3B: existing earned commissions are not recalculated unless HR explicitly applies a
 * backdated correction workflow — that workflow is a **Phase 20G** adjustment, not a 20F edit).
 *
 * Carries public ULIDs and configured terms only — safe to place directly in audit context.
 */
final readonly class CompensationImpactPreview
{
    public function __construct(
        public string $staffProfileUlid,
        public string $branchUlid,
        public string $planUlid,
        public ?string $supersedesPlanUlid,
        public string $compensationModel,
        public ?int $salaryAmountMinor,
        public ?string $salaryCurrency,
        public ?string $salaryPeriod,
        public ?string $commissionRuleUlid,
        public ?string $commissionCalculationType,
        public ?string $commissionCalculationBasis,
        public ?int $commissionPercentageBasisPoints,
        public ?int $commissionFixedAmountMinor,
        public ?string $commissionCurrency,
        public bool $appliesToPreferredPersonnelFee,
        public CarbonImmutable $effectiveFrom,
        public ?CarbonImmutable $effectiveTo,
        public bool $isBackdated,
        public CarbonImmutable $businessDate,
    ) {}

    /**
     * Audit/history-safe representation. Public ULIDs and configured terms only — no internal ids,
     * no personnel contact detail, no money that was earned.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'staff_profile_ulid' => $this->staffProfileUlid,
            'branch_ulid' => $this->branchUlid,
            'plan_ulid' => $this->planUlid,
            'supersedes_plan_ulid' => $this->supersedesPlanUlid,
            'compensation_model' => $this->compensationModel,
            'salary_amount_minor' => $this->salaryAmountMinor,
            'salary_currency' => $this->salaryCurrency,
            'salary_period' => $this->salaryPeriod,
            'commission_rule_ulid' => $this->commissionRuleUlid,
            'commission_calculation_type' => $this->commissionCalculationType,
            'commission_calculation_basis' => $this->commissionCalculationBasis,
            'commission_percentage_basis_points' => $this->commissionPercentageBasisPoints,
            'commission_fixed_amount_minor' => $this->commissionFixedAmountMinor,
            'commission_currency' => $this->commissionCurrency,
            'applies_to_preferred_personnel_fee' => $this->appliesToPreferredPersonnelFee,
            'effective_from' => $this->effectiveFrom->toDateString(),
            'effective_to' => $this->effectiveTo?->toDateString(),
            'is_backdated' => $this->isBackdated,
            'business_date' => $this->businessDate->toDateString(),
        ];
    }
}
