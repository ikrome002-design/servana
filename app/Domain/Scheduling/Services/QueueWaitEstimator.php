<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\PersonnelAvailabilityState;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use Carbon\CarbonImmutable;

/**
 * THE single deterministic queue wait estimator (Plan §37, §69; Phase 16B). The
 * result is operational GUIDANCE labelled "Estimate", never a guaranteed time.
 *
 * Deterministic baseline (data dictionary):
 *   queued_work_minutes = Σ service durations of active entries ahead of the target
 *     (an in_service entry with a known start contributes max(duration − elapsed, 0))
 *   active_capacity      = max(1, count of active eligible + available personnel)
 *   estimated_wait       = ceil(queued_work_minutes / active_capacity)
 *
 * Zero eligible personnel yields a safe finite estimate (no division by zero). A
 * manual override never overwrites the calculated value — both are retained.
 */
final class QueueWaitEstimator
{
    public function __construct(private readonly AvailabilityResolver $resolver) {}

    /** Calculated wait (minutes) for a single entry from current DB state. */
    public function estimateFor(QueueEntry $target): int
    {
        $ahead = QueueEntry::query()
            ->with('service:id,duration_minutes')
            ->where('branch_id', $target->branch_id)
            ->whereIn('status', QueueEntry::statusValues([
                QueueEntryStatus::Waiting,
                QueueEntryStatus::Assigned,
                QueueEntryStatus::Called,
                QueueEntryStatus::InService,
            ]))
            ->where('position', '<', $target->position)
            ->get();

        $work = 0;
        foreach ($ahead as $entry) {
            $service = $entry->service;
            $duration = $service === null ? 0 : (int) $service->duration_minutes;

            if ($entry->status === QueueEntryStatus::InService && $entry->started_at !== null) {
                $elapsed = (int) floor(CarbonImmutable::now()->diffInMinutes($entry->started_at, true));
                $work += max($duration - $elapsed, 0);
            } else {
                $work += $duration;
            }
        }

        $capacity = max(1, $this->availableCapacity($target));

        return (int) ceil($work / $capacity);
    }

    /**
     * Recalculate + persist `estimated_wait_minutes` for every active entry in a
     * branch (override value/reason preserved). Call inside the mutation transaction.
     */
    public function recalculateBranch(int $branchId): void
    {
        $entries = QueueEntry::query()
            ->where('branch_id', $branchId)
            ->active()
            ->orderBy('position')
            ->get();

        foreach ($entries as $entry) {
            $minutes = $this->estimateFor($entry);
            if ($entry->estimated_wait_minutes !== $minutes) {
                $entry->estimated_wait_minutes = $minutes;
                $entry->save();
            }
        }
    }

    /** Count active eligible + available personnel for the target entry's service. */
    private function availableCapacity(QueueEntry $target): int
    {
        $eligibleIds = ServicePersonnelEligibility::query()
            ->where('service_id', $target->service_id)
            ->where('active', true)
            ->pluck('staff_profile_id')
            ->all();

        if ($eligibleIds === []) {
            return 0;
        }

        $staff = StaffProfile::query()
            ->whereIn('id', $eligibleIds)
            ->where('merchant_id', $target->merchant_id)
            ->where('primary_branch_id', $target->branch_id)
            ->where('is_active', true)
            ->get();

        return $staff
            ->filter(fn (StaffProfile $s): bool => $this->resolver->currentState($s) === PersonnelAvailabilityState::Available)
            ->count();
    }
}
