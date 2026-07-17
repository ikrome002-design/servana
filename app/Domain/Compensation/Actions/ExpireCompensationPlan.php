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
use App\Domain\Compensation\Services\CompensationBusinessDate;
use App\Domain\Compensation\Services\CompensationPlanHistoryWriter;
use App\Domain\Compensation\Services\PersonnelCompensationPlanStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Apply the `active → expired` effective-date boundary (Plan §59; Phase 20F). Terminal. Monetary
 * terms unchanged — status only.
 *
 * An expired plan does not resolve: resolution then finds NO effective compensation configuration
 * (never a silent fallback to an older plan).
 *
 * **No ledger or payout effect.** This does not accrue, settle, or close out any money — it only
 * records that a configured window ended. **Phase 20F ships no salary-accrual scheduler**; the
 * cadence that drives this boundary in production is Phase 20G's concern.
 */
final class ExpireCompensationPlan
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PersonnelCompensationPlanStateMachine $stateMachine,
        private readonly CommissionRuleStateMachine $ruleStateMachine,
        private readonly CompensationBusinessDate $businessDate,
        private readonly CompensationPlanHistoryWriter $history,
    ) {}

    public function handle(PersonnelCompensationPlan $plan, User $actor): PersonnelCompensationPlan
    {
        return DB::transaction(function () use ($plan, $actor): PersonnelCompensationPlan {
            /** @var PersonnelCompensationPlan $locked */
            $locked = PersonnelCompensationPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            $from = $locked->status;
            $this->stateMachine->ensure($from, CompensationPlanStatus::Expired);

            if ($locked->effective_to === null || ! $this->businessDate->hasReached((string) $locked->effective_to)) {
                // Half-open [from, to): the plan is still effective until effective_to is reached.
                throw CompensationStateException::expiryBoundaryNotReached();
            }

            $locked->forceFill(['status' => CompensationPlanStatus::Expired->value])->save();
            $locked->refresh();

            $this->expireCommissionRule($locked, $actor);

            $this->history->record(
                $locked,
                CompensationPlanHistoryEvent::Expired,
                $from,
                CompensationPlanStatus::Expired,
                $actor,
                $locked->change_reason,
            );

            $this->audit->record(
                AuditEvent::CompensationPlanExpired,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'plan_id' => $locked->ulid,
                    'effective_to' => $locked->effective_to?->toDateString(),
                    'business_date' => $this->businessDate->today()->toDateString(),
                    'previous_state' => $from->value,
                    'new_state' => CompensationPlanStatus::Expired->value,
                ],
            );

            return $locked;
        });
    }

    private function expireCommissionRule(PersonnelCompensationPlan $plan, User $actor): void
    {
        $rule = $plan->commissionRule;

        if ($rule === null || $rule->status !== CommissionRuleStatus::Active) {
            return;
        }

        if ($rule->effective_to === null || ! $this->businessDate->hasReached((string) $rule->effective_to)) {
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

        $this->ruleStateMachine->ensure($rule->status, CommissionRuleStatus::Expired);

        $rule->forceFill(['status' => CommissionRuleStatus::Expired->value])->save();

        $this->audit->record(
            AuditEvent::CommissionRuleExpired,
            $actor,
            $rule->merchant_id,
            $rule->branch_id,
            $rule,
            [
                'commission_rule_id' => $rule->ulid,
                'plan_id' => $plan->ulid,
                'new_state' => CommissionRuleStatus::Expired->value,
            ],
        );
    }
}
