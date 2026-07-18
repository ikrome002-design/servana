<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Compensation\Enums\CommissionAppliesTo;
use App\Domain\Compensation\Enums\CommissionCalculationBasis;
use App\Domain\Compensation\Enums\CommissionCalculationType;
use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Exceptions\CompensationScopeException;
use App\Domain\Compensation\Exceptions\CompensationValidationException;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CommissionRuleService;
use App\Domain\Compensation\Services\CompensationShapeValidator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a DRAFT commission rule (Plan §59; Scope §12.7 Step 3A / §18.3; Phase 20F). HR-only,
 * branch-scoped, governed by `compensation.plan.create` — the matrix declares no
 * `commission.rule.*` namespace.
 *
 * A rule is a SIBLING configuration record referenced by a plan. It is created as a draft and then
 * has NO independent lifecycle: submission, approval, activation, ending, rejection and
 * cancellation are all driven by the referencing plan's actions (see the linkage table in
 * docs/architecture/state-machines/commission-rule.md).
 *
 * **Configuration only.** Computes no commission and creates no ledger row.
 */
final class CreateCommissionRuleDraft
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly CompensationShapeValidator $shape,
    ) {}

    /**
     * @param  list<Service>  $selectedServices  Resolved services for `selected_services` (scope pre-validated by the controller).
     */
    public function handle(
        MerchantBranch $branch,
        User $actor,
        CommissionCalculationType $calculationType,
        CommissionCalculationBasis $calculationBasis,
        CommissionAppliesTo $appliesTo,
        string $effectiveFrom,
        string $changeReason,
        ?int $percentageBasisPoints = null,
        ?int $fixedAmountMinor = null,
        ?string $currency = null,
        ?ServiceCategory $serviceCategory = null,
        bool $appliesToPreferredPersonnelFee = false,
        ?string $effectiveTo = null,
        ?string $notes = null,
        array $selectedServices = [],
    ): CommissionRule {
        $this->shape->ensureCommissionRuleShape($calculationType, $percentageBasisPoints, $fixedAmountMinor, $currency);

        if ($appliesTo->requiresServiceCategory() && ! $serviceCategory instanceof ServiceCategory) {
            throw CompensationValidationException::commissionRuleShape(
                'A category-scoped commission rule requires a service category.',
            );
        }

        if (! $appliesTo->requiresServiceCategory() && $serviceCategory instanceof ServiceCategory) {
            throw CompensationValidationException::commissionRuleShape(
                'This commission rule applicability cannot carry a service category.',
            );
        }

        if ($serviceCategory instanceof ServiceCategory
            && ($serviceCategory->merchant_id !== $branch->merchant_id || $serviceCategory->branch_id !== $branch->id)) {
            // A category from another branch is indistinguishable from one that does not exist.
            throw CompensationScopeException::commissionRule();
        }

        return DB::transaction(function () use (
            $branch, $actor, $calculationType, $calculationBasis, $appliesTo, $effectiveFrom, $changeReason,
            $percentageBasisPoints, $fixedAmountMinor, $currency, $serviceCategory,
            $appliesToPreferredPersonnelFee, $effectiveTo, $notes, $selectedServices,
        ): CommissionRule {
            /** @var CommissionRule $rule */
            $rule = CommissionRule::query()->create([
                'merchant_id' => $branch->merchant_id,
                'branch_id' => $branch->id,
                'calculation_type' => $calculationType,
                'percentage_basis_points' => $percentageBasisPoints,
                'fixed_amount_minor' => $fixedAmountMinor,
                'currency' => $currency,
                'calculation_basis' => $calculationBasis,
                'applies_to' => $appliesTo,
                'service_category_id' => $serviceCategory?->id,
                'applies_to_preferred_personnel_fee' => $appliesToPreferredPersonnelFee,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'status' => CommissionRuleStatus::Draft,
                'notes' => $notes,
                'change_reason' => $changeReason,
                'created_by' => $actor->id,
            ]);

            // §9.1 — persist one immutable membership row per selected service (draft only; the DB guard
            // freezes them once the rule leaves draft). Only `selected_services` carries memberships.
            if ($appliesTo === CommissionAppliesTo::SelectedServices) {
                foreach ($selectedServices as $service) {
                    CommissionRuleService::query()->create([
                        'merchant_id' => $rule->merchant_id,
                        'branch_id' => $rule->branch_id,
                        'commission_rule_id' => $rule->id,
                        'service_id' => $service->id,
                    ]);
                }
            }

            $this->audit->record(
                AuditEvent::CommissionRuleCreated,
                $actor,
                $rule->merchant_id,
                $rule->branch_id,
                $rule,
                [
                    'commission_rule_id' => $rule->ulid,
                    'calculation_type' => $rule->calculation_type->value,
                    'calculation_basis' => $rule->calculation_basis->value,
                    'applies_to' => $rule->applies_to->value,
                    'percentage_basis_points' => $rule->percentage_basis_points,
                    'fixed_amount_minor' => $rule->fixed_amount_minor,
                    'currency' => $rule->currency,
                    'applies_to_preferred_personnel_fee' => $rule->applies_to_preferred_personnel_fee,
                    'effective_from' => $rule->effective_from->toDateString(),
                    'effective_to' => $rule->effective_to?->toDateString(),
                    'new_state' => $rule->status->value,
                ],
            );

            return $rule;
        });
    }
}
