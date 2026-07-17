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
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Models\CommissionRule;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Services\CompensationPlanHistoryWriter;
use App\Domain\Compensation\Services\CompensationShapeValidator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a DRAFT compensation plan's terms in place (Plan §59; Phase 20F, F7). This is the ONLY
 * in-place edit in the aggregate: editing a `pending_approval`, `scheduled`, `active`, or terminal
 * plan is rejected here AND by the database's BEFORE UPDATE immutability trigger. Changing
 * effective terms is a SUPERSEDE (a new version), never an edit.
 *
 * The model shape is re-validated on EVERY edit — a draft can never be saved into a shape that
 * would be illegal at approval.
 */
final class UpdateCompensationPlanDraft
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly CompensationShapeValidator $shape,
        private readonly CompensationPlanHistoryWriter $history,
    ) {}

    public function handle(
        PersonnelCompensationPlan $plan,
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
        if (! $plan->status->isEditable()) {
            throw CompensationStateException::invalidTransition(
                'compensation plan',
                $plan->status->value,
                CompensationPlanStatus::Draft->value,
            );
        }

        if ($commissionRule !== null
            && ($commissionRule->merchant_id !== $plan->merchant_id || $commissionRule->branch_id !== $plan->branch_id)) {
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
            $plan, $actor, $model, $effectiveFrom, $changeReason, $commissionRule,
            $salaryAmountMinor, $salaryCurrency, $salaryPeriod, $salaryPayoutDay, $effectiveTo, $notes,
        ): PersonnelCompensationPlan {
            /** @var PersonnelCompensationPlan $locked */
            $locked = PersonnelCompensationPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            // Re-check under the lock: a concurrent submit must not be overwritten.
            if (! $locked->status->isEditable()) {
                throw CompensationStateException::invalidTransition(
                    'compensation plan',
                    $locked->status->value,
                    CompensationPlanStatus::Draft->value,
                );
            }

            $before = $this->terms($locked);

            $locked->forceFill([
                'compensation_model' => $model->value,
                'salary_amount_minor' => $salaryAmountMinor,
                'salary_currency' => $salaryCurrency,
                'salary_period' => $salaryPeriod?->value,
                'salary_payout_day' => $salaryPayoutDay,
                'commission_rule_id' => $commissionRule?->id,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'notes' => $notes,
                'change_reason' => $changeReason,
            ])->save();

            $locked->refresh();

            $this->history->record(
                $locked,
                CompensationPlanHistoryEvent::UpdatedDraft,
                CompensationPlanStatus::Draft,
                CompensationPlanStatus::Draft,
                $actor,
                $changeReason,
                ['before' => $before, 'after' => $this->terms($locked)],
            );

            $this->audit->record(
                AuditEvent::CompensationPlanUpdatedDraft,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'plan_id' => $locked->ulid,
                    'compensation_model' => $locked->compensation_model->value,
                    'new_state' => $locked->status->value,
                ],
            );

            return $locked;
        });
    }

    /** @return array<string, mixed> masked diff of configured terms — no internal ids */
    private function terms(PersonnelCompensationPlan $plan): array
    {
        return [
            'compensation_model' => $plan->compensation_model->value,
            'salary_amount_minor' => $plan->salary_amount_minor,
            'salary_currency' => $plan->salary_currency,
            'salary_period' => $plan->salary_period?->value,
            'salary_payout_day' => $plan->salary_payout_day,
            'commission_rule_id' => $plan->commissionRule?->ulid,
            'effective_from' => $plan->effective_from->toDateString(),
            'effective_to' => $plan->effective_to?->toDateString(),
        ];
    }
}
