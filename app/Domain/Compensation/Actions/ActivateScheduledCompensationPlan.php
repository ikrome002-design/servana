<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Enums\CompensationPlanHistoryEvent;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Exceptions\CompensationOverlapException;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Services\CommissionRuleStateMachine;
use App\Domain\Compensation\Services\CompensationBusinessDate;
use App\Domain\Compensation\Services\CompensationPlanHistoryWriter;
use App\Domain\Compensation\Services\PersonnelCompensationPlanStateMachine;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Apply the `scheduled → active` effective-date boundary (Plan §59; Phase 20F). Already approved —
 * this only recognizes that the configured `effective_from` has been reached in `Africa/Nairobi`.
 *
 * Status-only: no monetary, effective, or subject field is touched (the DB immutability trigger
 * permits exactly this shape). The plan was approved when it was scheduled, so no approval control
 * is re-run here.
 *
 * Writes the **`activated`** history event — the symmetric partner of `expired`. Recording it as
 * `approved` would collapse two distinct lifecycle moments; omitting it would make activation
 * invisible in compensation history. (Increment 3 correction — see docs/proof/phase-20f.md.)
 *
 * Supersedes the incumbent, if any, in the SAME transaction — a scheduled plan reaching its
 * boundary displaces the plan it was scheduled to replace.
 */
final class ActivateScheduledCompensationPlan
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

            DB::select('SELECT pg_advisory_xact_lock(?)', [
                crc32($locked->branch_id.':'.$locked->staff_profile_id),
            ]);

            $from = $locked->status;
            $this->stateMachine->ensure($from, CompensationPlanStatus::Active);

            if (! $this->businessDate->hasReached((string) $locked->effective_from)) {
                // Never activate a plan early: its window has not started.
                throw CompensationStateException::activationBoundaryNotReached();
            }

            $incumbent = $this->supersedeIncumbent($locked, $actor);

            try {
                $locked->forceFill(['status' => CompensationPlanStatus::Active->value])->save();
            } catch (QueryException $e) {
                if ($e->getCode() === '23P01') {
                    throw CompensationOverlapException::compensationPlan();
                }
                throw $e;
            }

            $locked->refresh();

            $this->activateCommissionRule($locked, $actor);

            $this->history->record(
                $locked,
                CompensationPlanHistoryEvent::Activated,
                $from,
                CompensationPlanStatus::Active,
                $actor,
                $locked->change_reason,
                ['superseded_plan_id' => $incumbent?->ulid],
            );

            $this->audit->record(
                AuditEvent::CompensationPlanActivated,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'plan_id' => $locked->ulid,
                    'effective_from' => $locked->effective_from->toDateString(),
                    'business_date' => $this->businessDate->today()->toDateString(),
                    'superseded_plan_id' => $incumbent?->ulid,
                    'previous_state' => $from->value,
                    'new_state' => CompensationPlanStatus::Active->value,
                ],
            );

            return $locked;
        });
    }

    /** Close out the incumbent at this plan's effective_from — adjacent, never overlapping. */
    private function supersedeIncumbent(PersonnelCompensationPlan $successor, User $actor): ?PersonnelCompensationPlan
    {
        /** @var PersonnelCompensationPlan|null $incumbent */
        $incumbent = PersonnelCompensationPlan::query()
            ->where('merchant_id', $successor->merchant_id)
            ->where('branch_id', $successor->branch_id)
            ->where('staff_profile_id', $successor->staff_profile_id)
            ->where('status', CompensationPlanStatus::Active)
            ->whereKeyNot($successor->id)
            ->lockForUpdate()
            ->first();

        if (! $incumbent instanceof PersonnelCompensationPlan) {
            return null;
        }

        $this->stateMachine->ensure($incumbent->status, CompensationPlanStatus::Superseded);

        $update = ['status' => CompensationPlanStatus::Superseded->value];

        if ($incumbent->effective_to === null) {
            $update['effective_to'] = $successor->effective_from->toDateString();
        }

        $incumbent->forceFill($update)->save();
        $incumbent->refresh();

        $this->history->record(
            $incumbent,
            CompensationPlanHistoryEvent::Superseded,
            CompensationPlanStatus::Active,
            CompensationPlanStatus::Superseded,
            $actor,
            $incumbent->change_reason,
            ['superseded_by_plan_id' => $successor->ulid],
        );

        $this->audit->record(
            AuditEvent::CompensationPlanSuperseded,
            $actor,
            $incumbent->merchant_id,
            $incumbent->branch_id,
            $incumbent,
            [
                'plan_id' => $incumbent->ulid,
                'superseded_by_plan_id' => $successor->ulid,
                'effective_to' => $incumbent->effective_to?->toDateString(),
                'previous_state' => CompensationPlanStatus::Active->value,
                'new_state' => CompensationPlanStatus::Superseded->value,
            ],
        );

        return $incumbent;
    }

    private function activateCommissionRule(PersonnelCompensationPlan $plan, User $actor): void
    {
        $rule = $plan->commissionRule;

        if ($rule === null || $rule->status !== CommissionRuleStatus::Scheduled) {
            return;
        }

        if (! $this->businessDate->hasReached((string) $rule->effective_from)) {
            return;
        }

        $this->ruleStateMachine->ensure($rule->status, CommissionRuleStatus::Active);

        $rule->forceFill(['status' => CommissionRuleStatus::Active->value])->save();

        $this->audit->record(
            AuditEvent::CommissionRuleActivated,
            $actor,
            $rule->merchant_id,
            $rule->branch_id,
            $rule,
            [
                'commission_rule_id' => $rule->ulid,
                'plan_id' => $plan->ulid,
                'new_state' => CommissionRuleStatus::Active->value,
            ],
        );
    }
}
