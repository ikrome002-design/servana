<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\CommissionRuleStatus;
use App\Domain\Compensation\Enums\CompensationPlanHistoryEvent;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Exceptions\CompensationApprovalException;
use App\Domain\Compensation\Exceptions\CompensationOverlapException;
use App\Domain\Compensation\Exceptions\CompensationValidationException;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Services\CommissionRuleStateMachine;
use App\Domain\Compensation\Services\CompensationBusinessDate;
use App\Domain\Compensation\Services\CompensationPlanHistoryWriter;
use App\Domain\Compensation\Services\PersonnelCompensationPlanStateMachine;
use App\Domain\Compensation\ValueObjects\CompensationImpactPreview;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Approve a PENDING_APPROVAL compensation plan (Plan §59; Phase 20F, F7/F8). The single most
 * controlled action in the phase.
 *
 * Controls, all enforced here and none skippable:
 *  - **maker/checker** — the submitter can never approve their own submission (also a DB CHECK);
 *  - **fresh step-up** — required; the caller asserts it and the action re-asserts it, so the
 *    domain can never approve without one;
 *  - **backdating (F8)** — a backdated plan requires an explicit reason AND an impact preview, and
 *    emits a CRITICAL audit event. An unapproved backdated change can never reach `active`.
 *
 * Target state is derived SERVER-SIDE from `effective_from`: a future window → `scheduled`; an
 * already-effective (incl. backdated) window → `active`.
 *
 * **Supersede is a consequence, not a permission** (Plan §59): when this plan becomes `active` and
 * an incumbent active plan exists for the same subject, the incumbent is closed out
 * (`active → superseded`, open-ended `effective_to` := this plan's `effective_from`) inside THIS
 * transaction. Half-open ranges make the windows adjacent, not overlapping. The incumbent's
 * monetary terms are never rewritten.
 *
 * Creates NO salary accrual, earned commission, ledger row, payout, or statement (20G/20H).
 */
final class ApproveCompensationPlan
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PersonnelCompensationPlanStateMachine $stateMachine,
        private readonly CommissionRuleStateMachine $ruleStateMachine,
        private readonly CompensationBusinessDate $businessDate,
        private readonly CompensationPlanHistoryWriter $history,
    ) {}

    /**
     * @param  bool  $hasFreshStepUp  the request layer's fresh step-up assertion (Increment 4 wires it)
     * @param  CompensationImpactPreview|null  $impactPreview  mandatory for a backdated plan (F8)
     */
    public function handle(
        PersonnelCompensationPlan $plan,
        User $actor,
        string $changeReason,
        bool $hasFreshStepUp,
        ?CompensationImpactPreview $impactPreview = null,
    ): PersonnelCompensationPlan {
        if (! $hasFreshStepUp) {
            throw CompensationApprovalException::freshStepUpRequired();
        }

        if (trim($changeReason) === '') {
            throw CompensationValidationException::backdatedApprovalRequiresReason();
        }

        return DB::transaction(function () use ($plan, $actor, $changeReason, $impactPreview): PersonnelCompensationPlan {
            /** @var PersonnelCompensationPlan $locked */
            $locked = PersonnelCompensationPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            // Serialize approvals for this subject so two concurrent approvals cannot both pass the
            // overlap pre-check. The DB EXCLUDE is still the final arbiter.
            DB::select('SELECT pg_advisory_xact_lock(?)', [
                crc32($locked->branch_id.':'.$locked->staff_profile_id),
            ]);

            // F8 maker/checker: also a DB CHECK, so a bypass fails at the database too.
            if ($locked->submitted_by !== null && $locked->submitted_by === $actor->id) {
                throw CompensationApprovalException::makerChecker();
            }

            $isBackdated = (bool) $locked->is_backdated;

            if ($isBackdated && ! $impactPreview instanceof CompensationImpactPreview) {
                // Fail closed: a backdated change is never approved without its impact preview.
                throw CompensationValidationException::backdatedApprovalRequiresImpactPreview();
            }

            $from = $locked->status;
            $target = $this->businessDate->hasReached((string) $locked->effective_from)
                ? CompensationPlanStatus::Active
                : CompensationPlanStatus::Scheduled;

            $this->stateMachine->ensure($from, $target);

            $incumbent = $target === CompensationPlanStatus::Active
                ? $this->closeOutIncumbent($locked, $actor, $changeReason)
                : null;

            try {
                $locked->forceFill([
                    'status' => $target->value,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'supersedes_plan_id' => $incumbent instanceof PersonnelCompensationPlan
                        ? $incumbent->id
                        : $locked->supersedes_plan_id,
                    'change_reason' => $changeReason,
                ])->save();
            } catch (QueryException $e) {
                if ($e->getCode() === '23P01') {
                    // The DB EXCLUDE rejected an overlapping active/scheduled window.
                    throw CompensationOverlapException::compensationPlan();
                }
                throw $e;
            }

            $locked->refresh();

            $this->approveCommissionRule($locked, $actor);

            $preview = $impactPreview?->toArray();

            $this->history->record(
                $locked,
                CompensationPlanHistoryEvent::Approved,
                $from,
                $target,
                $actor,
                $changeReason,
                $preview,
            );

            $context = [
                'plan_id' => $locked->ulid,
                'staff_profile_id' => $locked->staffProfile?->ulid,
                'compensation_model' => $locked->compensation_model->value,
                'salary_amount_minor' => $locked->salary_amount_minor,
                'salary_currency' => $locked->salary_currency,
                'salary_period' => $locked->salary_period?->value,
                'commission_rule_id' => $locked->commissionRule?->ulid,
                'effective_from' => $locked->effective_from->toDateString(),
                'effective_to' => $locked->effective_to?->toDateString(),
                'is_backdated' => $isBackdated,
                'superseded_plan_id' => $incumbent?->ulid,
                'business_date' => $this->businessDate->today()->toDateString(),
                'previous_state' => $from->value,
                'new_state' => $target->value,
            ];

            if ($preview !== null) {
                $context['impact_preview'] = $preview;
            }

            $this->audit->record(
                AuditEvent::CompensationPlanApproved,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $context,
            );

            if ($isBackdated) {
                // Plan §59: an approved backdated change is CRITICAL severity.
                $this->audit->record(
                    AuditEvent::CompensationPlanBackdatedChangeApproved,
                    $actor,
                    $locked->merchant_id,
                    $locked->branch_id,
                    $locked,
                    $context,
                );
            }

            return $locked;
        });
    }

    /**
     * Close out the incumbent active plan for this subject, in the SAME transaction. The incumbent
     * moves `active → superseded` and its open-ended window is closed AT the successor's
     * `effective_from` — adjacent, never overlapping. Its terms are never rewritten.
     */
    private function closeOutIncumbent(
        PersonnelCompensationPlan $successor,
        User $actor,
        string $changeReason,
    ): ?PersonnelCompensationPlan {
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

        // Only an OPEN-ENDED incumbent needs closing; a window that already ends earlier is left
        // untouched (the DB trigger permits exactly the open → closed shape).
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
            $changeReason,
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

        $this->endCommissionRule($incumbent, $successor, $actor);

        return $incumbent;
    }

    /** The successor's rule is approved with its plan; its target follows the RULE's own window. */
    private function approveCommissionRule(PersonnelCompensationPlan $plan, User $actor): void
    {
        $rule = $plan->commissionRule;

        if ($rule === null || $rule->status !== CommissionRuleStatus::PendingApproval) {
            return;
        }

        $target = $this->businessDate->hasReached((string) $rule->effective_from)
            ? CommissionRuleStatus::Active
            : CommissionRuleStatus::Scheduled;

        $this->ruleStateMachine->ensure($rule->status, $target);

        $rule->forceFill([
            'status' => $target->value,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ])->save();

        $this->audit->record(
            AuditEvent::CommissionRuleApproved,
            $actor,
            $rule->merchant_id,
            $rule->branch_id,
            $rule,
            [
                'commission_rule_id' => $rule->ulid,
                'plan_id' => $plan->ulid,
                'calculation_type' => $rule->calculation_type->value,
                'calculation_basis' => $rule->calculation_basis->value,
                'percentage_basis_points' => $rule->percentage_basis_points,
                'fixed_amount_minor' => $rule->fixed_amount_minor,
                'currency' => $rule->currency,
                'applies_to_preferred_personnel_fee' => $rule->applies_to_preferred_personnel_fee,
                'new_state' => $target->value,
            ],
        );
    }

    /**
     * END the incumbent's rule — never delete it (Scope §12.7 Step 3C). Skipped when the successor
     * reuses the same rule, or when another non-terminal plan still depends on it: ending a rule a
     * live plan still references would break that plan's F1 model shape.
     */
    private function endCommissionRule(
        PersonnelCompensationPlan $incumbent,
        PersonnelCompensationPlan $successor,
        User $actor,
    ): void {
        $rule = $incumbent->commissionRule;

        if ($rule === null || $rule->status !== CommissionRuleStatus::Active) {
            return;
        }

        if ($rule->id === $successor->commission_rule_id) {
            return;
        }

        $stillReferenced = PersonnelCompensationPlan::query()
            ->where('commission_rule_id', $rule->id)
            ->whereKeyNot($incumbent->id)
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

        $this->ruleStateMachine->ensure($rule->status, CommissionRuleStatus::Superseded);

        $update = ['status' => CommissionRuleStatus::Superseded->value];

        if ($rule->effective_to === null) {
            $update['effective_to'] = $successor->effective_from->toDateString();
        }

        $before = [
            'percentage_basis_points' => $rule->percentage_basis_points,
            'fixed_amount_minor' => $rule->fixed_amount_minor,
            'currency' => $rule->currency,
            'calculation_basis' => $rule->calculation_basis->value,
        ];

        $rule->forceFill($update)->save();

        $this->audit->record(
            AuditEvent::CommissionRuleEnded,
            $actor,
            $rule->merchant_id,
            $rule->branch_id,
            $rule,
            [
                'commission_rule_id' => $rule->ulid,
                'successor_commission_rule_id' => $successor->commissionRule?->ulid,
                'terms' => $before, // unchanged by ending — recorded for the audit trail
                'previous_state' => CommissionRuleStatus::Active->value,
                'new_state' => CommissionRuleStatus::Superseded->value,
            ],
        );
    }
}
