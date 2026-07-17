<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\CompensationModel;
use App\Domain\Compensation\Enums\CompensationPlanHistoryEvent;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Enums\SalaryPeriod;
use App\Domain\Compensation\Exceptions\CompensationScopeException;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Services\CompensationPlanHistoryWriter;
use App\Domain\Compensation\Services\CompensationShapeValidator;
use App\Domain\Hr\Models\StaffProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a DRAFT compensation plan (Plan §59; Scope §12.2-§12.9; Phase 20F). HR-only,
 * branch-scoped. A draft is the ONLY editable state and takes effect for nobody: it is not
 * approved, not effective, and does not participate in the overlap exclusion.
 *
 * Server owns `merchant_id`/`branch_id`/`status`/actor columns — a caller can never supply them.
 * The subject and the commission rule are re-resolved INSIDE tenant+branch scope, so a foreign ULID
 * is a 404, never a cross-tenant write.
 *
 * **A compensation plan grants no access** (Plan §59) and creates no financial fact.
 */
final class CreateCompensationPlanDraft
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly CompensationShapeValidator $shape,
        private readonly CompensationPlanHistoryWriter $history,
    ) {}

    public function handle(
        StaffProfile $staffProfile,
        int $branchId,
        User $actor,
        CompensationModel $model,
        string $effectiveFrom,
        string $changeReason,
        ?CommissionRule $commissionRule = null,
        ?int $salaryAmountMinor = null,
        ?string $salaryCurrency = null,
        ?SalaryPeriod $salaryPeriod = null,
        ?int $salaryPayoutDay = null,
        ?string $effectiveTo = null,
        ?string $notes = null,
    ): PersonnelCompensationPlan {
        // The subject must live in the acting branch: HR is same-branch only (Plan §179).
        if ($staffProfile->primary_branch_id !== $branchId) {
            throw CompensationScopeException::staffProfile();
        }

        $merchantId = $staffProfile->merchant_id;

        if ($commissionRule !== null
            && ($commissionRule->merchant_id !== $merchantId || $commissionRule->branch_id !== $branchId)) {
            throw CompensationScopeException::commissionRule();
        }

        $this->shape->ensureCompensationModelShape(
            $model,
            $salaryAmountMinor,
            $salaryCurrency,
            $salaryPeriod?->value,
            $salaryPayoutDay,
            $commissionRule?->id,
        );

        return DB::transaction(function () use (
            $staffProfile, $branchId, $merchantId, $actor, $model, $effectiveFrom, $changeReason,
            $commissionRule, $salaryAmountMinor, $salaryCurrency, $salaryPeriod, $salaryPayoutDay,
            $effectiveTo, $notes,
        ): PersonnelCompensationPlan {
            /** @var PersonnelCompensationPlan $plan */
            $plan = PersonnelCompensationPlan::query()->create([
                'merchant_id' => $merchantId,
                'branch_id' => $branchId,
                'staff_profile_id' => $staffProfile->id,
                'compensation_model' => $model,
                'salary_amount_minor' => $salaryAmountMinor,
                'salary_currency' => $salaryCurrency,
                'salary_period' => $salaryPeriod,
                'salary_payout_day' => $salaryPayoutDay,
                'commission_rule_id' => $commissionRule?->id,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'status' => CompensationPlanStatus::Draft,
                'is_backdated' => false, // computed at SUBMISSION (F8), never accepted from input
                'notes' => $notes,
                'change_reason' => $changeReason,
                'created_by' => $actor->id,
            ]);

            $this->history->record(
                $plan,
                CompensationPlanHistoryEvent::Created,
                null, // `created` is the only event with no prior status
                CompensationPlanStatus::Draft,
                $actor,
                $changeReason,
            );

            $this->audit->record(
                AuditEvent::CompensationPlanCreated,
                $actor,
                $merchantId,
                $branchId,
                $plan,
                $this->auditContext($plan),
            );

            return $plan;
        });
    }

    /** @return array<string, mixed> public ULIDs + configured terms only; never contact detail or internal ids */
    private function auditContext(PersonnelCompensationPlan $plan): array
    {
        return [
            'plan_id' => $plan->ulid,
            'staff_profile_id' => $plan->staffProfile?->ulid,
            'compensation_model' => $plan->compensation_model->value,
            'salary_amount_minor' => $plan->salary_amount_minor,
            'salary_currency' => $plan->salary_currency,
            'salary_period' => $plan->salary_period?->value,
            'commission_rule_id' => $plan->commissionRule?->ulid,
            'effective_from' => $plan->effective_from->toDateString(),
            'effective_to' => $plan->effective_to?->toDateString(),
            'new_state' => $plan->status->value,
        ];
    }
}
