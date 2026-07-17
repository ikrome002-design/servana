<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Enums\CompensationPlanHistoryEvent;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Services\CommissionRuleStateMachine;
use App\Domain\Compensation\Services\CompensationPlanHistoryWriter;
use App\Domain\Compensation\Services\PersonnelCompensationPlanStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a DRAFT or SCHEDULED compensation plan before it ever takes effect (Plan §59; Phase 20F).
 *
 * An ACTIVE plan is NEVER cancelled — it is SUPERSEDED by approving a successor (the state machine
 * rejects `active → cancelled`). A cancelled plan never resolves and never blocks a new effective
 * window.
 */
final class CancelCompensationPlan
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PersonnelCompensationPlanStateMachine $stateMachine,
        private readonly CommissionRuleStateMachine $ruleStateMachine,
        private readonly CompensationPlanHistoryWriter $history,
    ) {}

    public function handle(PersonnelCompensationPlan $plan, User $actor, string $changeReason): PersonnelCompensationPlan
    {
        if (trim($changeReason) === '') {
            throw CompensationStateException::reasonRequired();
        }

        return DB::transaction(function () use ($plan, $actor, $changeReason): PersonnelCompensationPlan {
            /** @var PersonnelCompensationPlan $locked */
            $locked = PersonnelCompensationPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            $from = $locked->status;
            $this->stateMachine->ensure($from, CompensationPlanStatus::Cancelled);

            $locked->forceFill([
                'status' => CompensationPlanStatus::Cancelled->value,
                'change_reason' => $changeReason,
            ])->save();

            $locked->refresh();

            $this->cancelCommissionRule($locked, $actor);

            $this->history->record(
                $locked,
                CompensationPlanHistoryEvent::Cancelled,
                $from,
                CompensationPlanStatus::Cancelled,
                $actor,
                $changeReason,
            );

            $this->audit->record(
                AuditEvent::CompensationPlanCancelled,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'plan_id' => $locked->ulid,
                    'reason' => $changeReason,
                    'previous_state' => $from->value,
                    'new_state' => $locked->status->value,
                ],
            );

            return $locked;
        });
    }

    /** Cancel the rule with its plan — unless another live plan still depends on it. */
    private function cancelCommissionRule(PersonnelCompensationPlan $plan, User $actor): void
    {
        $rule = $plan->commissionRule;

        if ($rule === null || $rule->status->isTerminal() || $rule->status === CommissionRuleStatus::Active) {
            return;
        }

        if (! $this->ruleStateMachine->canTransition($rule->status, CommissionRuleStatus::Cancelled)) {
            return;
        }

        $stillReferenced = PersonnelCompensationPlan::query()
            ->where('commission_rule_id', $rule->id)
            ->whereKeyNot($plan->id)
            ->whereIn('status', [
                CompensationPlanStatus::Draft,
                CompensationPlanStatus::PendingApproval,
                CompensationPlanStatus::Scheduled,
                CompensationPlanStatus::Active,
            ])
            ->exists();

        if ($stillReferenced) {
            return;
        }

        $rule->forceFill(['status' => CommissionRuleStatus::Cancelled->value])->save();

        $this->audit->record(
            AuditEvent::CommissionRuleCancelled,
            $actor,
            $rule->merchant_id,
            $rule->branch_id,
            $rule,
            [
                'commission_rule_id' => $rule->ulid,
                'plan_id' => $plan->ulid,
                'new_state' => CommissionRuleStatus::Cancelled->value,
            ],
        );
    }
}
