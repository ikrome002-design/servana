<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Enums\CompensationPlanHistoryEvent;
use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Models\CompensationPlanHistory;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Models\User;

/**
 * Appends `compensation_plan_history` rows (Plan §59, §80; Scope §12; Phase 20F). The single
 * writer, so every lifecycle moment is recorded the same way and none can be silently skipped.
 *
 * Always called INSIDE the transaction of the transition that produced the row, so history and the
 * transition commit or roll back together. The table is append-only at the database (BEFORE UPDATE
 * OR DELETE trigger) — this writer only ever inserts.
 *
 * **Not a ledger:** records configuration changes, never money owed, accrued, earned, or paid.
 */
final class CompensationPlanHistoryWriter
{
    /**
     * @param  array<string, mixed>|null  $changedFields  masked diff / impact summary of configured terms
     */
    public function record(
        PersonnelCompensationPlan $plan,
        CompensationPlanHistoryEvent $event,
        ?CompensationPlanStatus $fromStatus,
        CompensationPlanStatus $toStatus,
        User $actor,
        string $changeReason,
        ?array $changedFields = null,
    ): CompensationPlanHistory {
        return CompensationPlanHistory::query()->create([
            'merchant_id' => $plan->merchant_id,
            'branch_id' => $plan->branch_id,
            'compensation_plan_id' => $plan->id,
            'staff_profile_id' => $plan->staff_profile_id,
            'event' => $event,
            // `created` is the only event with no prior status (DB CHECK).
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_fields' => $changedFields,
            'was_backdated' => (bool) $plan->is_backdated,
            'change_reason' => $changeReason,
            'actor_user_id' => $actor->id,
            'effective_from' => $plan->effective_from,
        ]);
    }
}
