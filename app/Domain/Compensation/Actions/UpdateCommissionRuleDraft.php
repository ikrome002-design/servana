<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Compensation\Enums\CommissionAppliesTo;
use App\Domain\Compensation\Enums\CommissionCalculationBasis;
use App\Domain\Compensation\Enums\CommissionCalculationType;
use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Exceptions\CompensationScopeException;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Exceptions\CompensationValidationException;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\CommissionRuleService;
use App\Domain\Compensation\Services\CompensationShapeValidator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a DRAFT commission rule's terms in place (Plan §59; Phase 20F, F7). The only in-place edit
 * a rule ever gets: editing an `active`/`scheduled`/terminal rule is rejected here AND by the
 * database's BEFORE UPDATE immutability trigger. An active rule's terms change only by SUPERSEDE,
 * and a previously active rule is ENDED, never deleted (Scope §12.7 Step 3C).
 *
 * The value shape is re-validated on EVERY edit.
 */
final class UpdateCommissionRuleDraft
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly CompensationShapeValidator $shape,
    ) {}

    /**
     * @param  list<Service>  $selectedServices  Resolved services for `selected_services` (scope pre-validated by the controller).
     */
    public function handle(
        CommissionRule $rule,
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
        if (! $rule->status->isEditable()) {
            throw CompensationStateException::invalidTransition(
                'commission rule',
                $rule->status->value,
                CommissionRuleStatus::Draft->value,
            );
        }

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
            && ($serviceCategory->merchant_id !== $rule->merchant_id || $serviceCategory->branch_id !== $rule->branch_id)) {
            throw CompensationScopeException::commissionRule();
        }

        return DB::transaction(function () use (
            $rule, $actor, $calculationType, $calculationBasis, $appliesTo, $effectiveFrom, $changeReason,
            $percentageBasisPoints, $fixedAmountMinor, $currency, $serviceCategory,
            $appliesToPreferredPersonnelFee, $effectiveTo, $notes, $selectedServices,
        ): CommissionRule {
            /** @var CommissionRule $locked */
            $locked = CommissionRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();

            // Re-check under the lock: a concurrent submit must not be overwritten.
            if (! $locked->status->isEditable()) {
                throw CompensationStateException::invalidTransition(
                    'commission rule',
                    $locked->status->value,
                    CommissionRuleStatus::Draft->value,
                );
            }

            $locked->forceFill([
                'calculation_type' => $calculationType->value,
                'percentage_basis_points' => $percentageBasisPoints,
                'fixed_amount_minor' => $fixedAmountMinor,
                'currency' => $currency,
                'calculation_basis' => $calculationBasis->value,
                'applies_to' => $appliesTo->value,
                'service_category_id' => $serviceCategory?->id,
                'applies_to_preferred_personnel_fee' => $appliesToPreferredPersonnelFee,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'notes' => $notes,
                'change_reason' => $changeReason,
            ])->save();

            $locked->refresh();

            // §9.1 — replace the draft's membership set atomically. The rule is draft under lock, so the
            // DB guard permits delete+insert. A move AWAY from `selected_services` clears stale memberships;
            // a move TO it (re)inserts the resolved set. Historical (non-draft) memberships never reach here.
            CommissionRuleService::query()->where('commission_rule_id', $locked->id)->delete();
            if ($appliesTo === CommissionAppliesTo::SelectedServices) {
                foreach ($selectedServices as $service) {
                    CommissionRuleService::query()->create([
                        'merchant_id' => $locked->merchant_id,
                        'branch_id' => $locked->branch_id,
                        'commission_rule_id' => $locked->id,
                        'service_id' => $service->id,
                    ]);
                }
            }

            $this->audit->record(
                AuditEvent::CommissionRuleUpdatedDraft,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'commission_rule_id' => $locked->ulid,
                    'calculation_type' => $locked->calculation_type->value,
                    'percentage_basis_points' => $locked->percentage_basis_points,
                    'fixed_amount_minor' => $locked->fixed_amount_minor,
                    'currency' => $locked->currency,
                    'applies_to_preferred_personnel_fee' => $locked->applies_to_preferred_personnel_fee,
                    'new_state' => $locked->status->value,
                ],
            );

            return $locked;
        });
    }
}
