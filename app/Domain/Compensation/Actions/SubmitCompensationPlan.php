<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Enums\CompensationPlanHistoryEvent;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Services\CommissionRuleStateMachine;
use App\Domain\Compensation\Services\CompensationBusinessDate;
use App\Domain\Compensation\Services\CompensationPlanHistoryWriter;
use App\Domain\Compensation\Services\PersonnelCompensationPlanStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Submit a DRAFT compensation plan for approval (Plan §59; Phase 20F, F8). Freezes the terms: after
 * this, `update_draft` is rejected by the state machine AND the database trigger.
 *
 * Submission NEVER approves, NEVER activates, and NEVER supersedes an incumbent — it only records
 * who is asking. `is_backdated` is COMPUTED here from the Africa/Nairobi business date and stored;
 * it is never accepted from input.
 *
 * The referencing plan's draft commission rule is submitted in the SAME transaction (a rule has no
 * independent lifecycle — see docs/architecture/state-machines/commission-rule.md).
 */
final class SubmitCompensationPlan
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PersonnelCompensationPlanStateMachine $stateMachine,
        private readonly CommissionRuleStateMachine $ruleStateMachine,
        private readonly CompensationBusinessDate $businessDate,
        private readonly CompensationPlanHistoryWriter $history,
    ) {}

    public function handle(PersonnelCompensationPlan $plan, User $actor, string $changeReason): PersonnelCompensationPlan
    {
        return DB::transaction(function () use ($plan, $actor, $changeReason): PersonnelCompensationPlan {
            /** @var PersonnelCompensationPlan $locked */
            $locked = PersonnelCompensationPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            $from = $locked->status;
            $this->stateMachine->ensure($from, CompensationPlanStatus::PendingApproval);

            // F8: computed at submission from the business date, never supplied by the caller.
            $isBackdated = $this->businessDate->isBackdated((string) $locked->effective_from);

            $locked->forceFill([
                'status' => CompensationPlanStatus::PendingApproval->value,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'is_backdated' => $isBackdated,
                'change_reason' => $changeReason,
            ])->save();

            $locked->refresh();

            $this->submitCommissionRule($locked, $actor);

            $this->history->record(
                $locked,
                CompensationPlanHistoryEvent::Submitted,
                $from,
                CompensationPlanStatus::PendingApproval,
                $actor,
                $changeReason,
            );

            $this->audit->record(
                AuditEvent::CompensationPlanSubmitted,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'plan_id' => $locked->ulid,
                    'compensation_model' => $locked->compensation_model->value,
                    'effective_from' => $locked->effective_from->toDateString(),
                    'is_backdated' => $isBackdated,
                    'previous_state' => $from->value,
                    'new_state' => $locked->status->value,
                ],
            );

            return $locked;
        });
    }

    /** A draft rule is submitted with its plan; an already-submitted/shared rule is left alone. */
    private function submitCommissionRule(PersonnelCompensationPlan $plan, User $actor): void
    {
        $rule = $plan->commissionRule;

        if ($rule === null || $rule->status !== CommissionRuleStatus::Draft) {
            return;
        }

        $this->ruleStateMachine->ensure($rule->status, CommissionRuleStatus::PendingApproval);

        $rule->forceFill(['status' => CommissionRuleStatus::PendingApproval->value])->save();

        $this->audit->record(
            AuditEvent::CommissionRuleSubmitted,
            $actor,
            $rule->merchant_id,
            $rule->branch_id,
            $rule,
            [
                'commission_rule_id' => $rule->ulid,
                'plan_id' => $plan->ulid,
                'new_state' => CommissionRuleStatus::PendingApproval->value,
            ],
        );
    }
}
