<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Exceptions\CompensationResolutionException;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use App\Domain\Compensation\Services\CompensationBusinessDate;
use App\Domain\Hr\Models\StaffProfile;
use Carbon\CarbonInterface;

/**
 * Resolve the effective compensation plan for a personnel in a branch on a date (Plan §59;
 * Scope §12.9; Phase 20F).
 *
 * The effective plan is the `active` plan for (staff_profile_id, branch_id) whose half-open
 * daterange `[effective_from, effective_to)` contains the business date. The DB EXCLUDE guarantees
 * AT MOST ONE such row.
 *
 * **Configuration only.** Returns configuration, computes no money, creates no row, has no side
 * effects. Phase 20G consumes it to accrue salary and earn commission; Phase 20F never does.
 *
 * Fails closed: none found → `null` (NEVER a silent default). More than one → a typed invariant
 * violation rather than arbitrarily picking one.
 */
final class ResolveEffectiveCompensationPlan
{
    public function __construct(private readonly CompensationBusinessDate $businessDate) {}

    /**
     * @throws CompensationResolutionException when the one-active-plan invariant is broken
     */
    public function handle(
        StaffProfile $staffProfile,
        int $branchId,
        CarbonInterface|string|null $date = null,
    ): ?PersonnelCompensationPlan {
        $on = $date === null ? $this->businessDate->today() : $this->businessDate->normalize($date);

        $matches = PersonnelCompensationPlan::query()
            ->where('merchant_id', $staffProfile->merchant_id)
            ->where('branch_id', $branchId)
            ->where('staff_profile_id', $staffProfile->id)
            // Only `active` resolves: draft/pending_approval/scheduled and every terminal status
            // are excluded, so a plan never takes effect before it is approved and effective.
            ->where('status', CompensationPlanStatus::Active)
            ->whereDate('effective_from', '<=', $on->toDateString())
            ->where(function ($query) use ($on): void {
                // Half-open [from, to): a plan ending ON the date is no longer effective.
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>', $on->toDateString());
            })
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();

        if ($matches->count() > 1) {
            // Unreachable while the DB EXCLUDE holds. If it is ever reached the invariant is
            // broken, so refuse to guess which plan is real.
            throw CompensationResolutionException::effectivePlanConflict();
        }

        return $matches->first();
    }
}
