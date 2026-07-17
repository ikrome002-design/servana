<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Exceptions\CompensationResolutionException;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Services\CompensationBusinessDate;
use Carbon\CarbonInterface;

/**
 * Resolve the effective commission rule for a compensation plan on a date (Plan §59; Scope §12.7;
 * Phase 20F, F5).
 *
 * `salary_only` → **null, ALWAYS** (Plan §80 named test "salary-only has no commission rule"): the
 * DB model-shape CHECK keeps `commission_rule_id` NULL, so no rule can ever be resolved for that
 * personnel and Phase 20G can never earn commission for them (Scope §12.5).
 *
 * `commission_only` / `salary_plus_commission` → the referenced rule must be `active` and its
 * effective window must contain the date, else this FAILS CLOSED with
 * `effective_commission_rule_missing` — never a silent default rate.
 *
 * **Configuration only.** Computes no commission, allocates nothing, creates no row. Phase 20G
 * computes `round_half_up(basis_minor * percentage_basis_points / 10000)` per ADR-005 — not here.
 */
final class ResolveEffectiveCommissionRule
{
    public function __construct(private readonly CompensationBusinessDate $businessDate) {}

    /**
     * @throws CompensationResolutionException when a commission-bearing model has no effective rule
     */
    public function handle(
        PersonnelCompensationPlan $plan,
        CarbonInterface|string|null $date = null,
    ): ?CommissionRule {
        if (! $plan->compensation_model->requiresCommissionRule()) {
            // salary_only: no rule exists by DB CHECK, and none is expected.
            return null;
        }

        $on = $date === null ? $this->businessDate->today() : $this->businessDate->normalize($date);

        $rule = CommissionRule::query()
            ->where('merchant_id', $plan->merchant_id)
            ->where('branch_id', $plan->branch_id)
            ->whereKey($plan->commission_rule_id)
            ->where('status', CommissionRuleStatus::Active)
            ->whereDate('effective_from', '<=', $on->toDateString())
            ->where(function ($query) use ($on): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>', $on->toDateString());
            })
            ->first();

        if (! $rule instanceof CommissionRule) {
            // The model REQUIRES a rule; a missing/ended/not-yet-effective rule must never
            // silently degrade into "no commission".
            throw CompensationResolutionException::effectiveCommissionRuleMissing();
        }

        return $rule;
    }
}
