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
 * Reject a PENDING_APPROVAL compensation plan (Plan §59; Phase 20F). Terminal: a rejected plan is
 * never re-submitted — HR creates a new draft. A rejected plan never resolves as effective and
 * never blocks an effective window.
 *
 * Rejection NEVER touches the incumbent active plan: the personnel keeps earning exactly as before.
 */
final class RejectCompensationPlan
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
            $this->stateMachine->ensure($from, CompensationPlanStatus::Rejected);

            $locked->forceFill([
                'status' => CompensationPlanStatus::Rejected->value,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'change_reason' => $changeReason,
            ])->save();

            $locked->refresh();

            $this->rejectCommissionRule($locked, $actor);

            $this->history->record(
                $locked,
                CompensationPlanHistoryEvent::Rejected,
                $from,
                CompensationPlanStatus::Rejected,
                $actor,
                $changeReason,
            );

            $this->audit->record(
                AuditEvent::CompensationPlanRejected,
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

    private function rejectCommissionRule(PersonnelCompensationPlan $plan, User $actor): void
    {
        $rule = $plan->commissionRule;

        if ($rule === null || $rule->status !== CommissionRuleStatus::PendingApproval) {
            return;
        }

        $this->ruleStateMachine->ensure($rule->status, CommissionRuleStatus::Rejected);

        $rule->forceFill(['status' => CommissionRuleStatus::Rejected->value])->save();

        $this->audit->record(
            AuditEvent::CommissionRuleRejected,
            $actor,
            $rule->merchant_id,
            $rule->branch_id,
            $rule,
            [
                'commission_rule_id' => $rule->ulid,
                'plan_id' => $plan->ulid,
                'new_state' => CommissionRuleStatus::Rejected->value,
            ],
        );
    }
}
