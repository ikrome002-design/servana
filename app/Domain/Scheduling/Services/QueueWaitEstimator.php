<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\PersonnelAvailabilityState;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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
 *
 * `available` means the LIVE state from {@see PersonnelStateProjector}: a personnel member with an
 * `in_progress` service session is `busy` and is NOT capacity (Phase 24, PH24-QUEUE-002). Before
 * this the capacity count used the schedule-only {@see AvailabilityResolver}, which documents that
 * "`busy` is NOT computed here", so a personnel member mid-session still counted as a free server
 * and the advertised wait was under-estimated. `busy` remains DERIVED, never stored.
 *
 * Query cost (Phase 24, PH24-QUEUE-001): capacity for one service costs a CONSTANT four queries
 * regardless of how many personnel are eligible, and a whole-branch recalculation resolves capacity
 * once per distinct service and computes the work ahead by an in-memory prefix scan over the single
 * ordered entry load. Previously each entry re-resolved eligibility, staff and one availability
 * query PER personnel — O(entries × personnel).
 */
final class QueueWaitEstimator
{
    public function __construct(
        private readonly AvailabilityResolver $resolver,
        private readonly PersonnelStateProjector $projector,
    ) {}

    /**
     * The statuses that occupy a server and therefore contribute work AHEAD of a target entry.
     * Deliberately narrower than {@see QueueEntry::scopeActive()}, which also counts `transferred`.
     *
     * @return list<QueueEntryStatus>
     */
    private static function workAheadStatuses(): array
    {
        return [
            QueueEntryStatus::Waiting,
            QueueEntryStatus::Assigned,
            QueueEntryStatus::Called,
            QueueEntryStatus::InService,
        ];
    }

    /** Calculated wait (minutes) for a single entry from current DB state. */
    public function estimateFor(QueueEntry $target): int
    {
        $ahead = QueueEntry::query()
            ->with('service:id,duration_minutes')
            ->where('branch_id', $target->branch_id)
            ->whereIn('status', QueueEntry::statusValues(self::workAheadStatuses()))
            ->where('position', '<', $target->position)
            ->get();

        $work = 0;
        foreach ($ahead as $entry) {
            $work += $this->workContributedBy($entry);
        }

        return $this->divide($work, $this->availableCapacity($target));
    }

    /**
     * Recalculate + persist `estimated_wait_minutes` for every active entry in a
     * branch (override value/reason preserved). Call inside the mutation transaction.
     */
    public function recalculateBranch(int $branchId): void
    {
        $entries = QueueEntry::query()
            ->with('service:id,duration_minutes')
            ->where('branch_id', $branchId)
            ->active()
            ->orderBy('position')
            ->get();

        // Capacity depends only on the service (plus branch/merchant, which are fixed here), so it
        // is resolved once per distinct service rather than once per entry.
        $capacityByService = [];

        // Work ahead is a running prefix over the SAME ordered load — no per-entry re-query. Only
        // the four work-ahead statuses contribute; `transferred` entries are active but occupy no
        // server, exactly as estimateFor() has always treated them.
        $workAhead = 0;
        $contributes = self::workAheadStatuses();

        /** @var EloquentCollection<int, QueueEntry> $changed */
        $changed = new EloquentCollection;

        foreach ($entries as $entry) {
            $serviceId = (int) $entry->service_id;
            $capacityByService[$serviceId] ??= $this->availableCapacity($entry);

            $minutes = $this->divide($workAhead, $capacityByService[$serviceId]);

            if ($entry->estimated_wait_minutes !== $minutes) {
                $entry->estimated_wait_minutes = $minutes;
                $changed->push($entry);
            }

            if (in_array($entry->status, $contributes, true)) {
                $workAhead += $this->workContributedBy($entry);
            }
        }

        if ($changed->isEmpty()) {
            return;
        }

        // Persist first with search syncing suspended, then re-index the whole changed set ONCE.
        // Scout's per-save observer would otherwise index each entry individually and eager-load
        // that entry's index relations separately — an N+1 proportional to the queue length
        // (Phase 24, PH24-QUEUE-003). Batching leaves the index in exactly the same state: the same
        // documents are pushed, with their relations loaded once for the whole collection.
        QueueEntry::withoutSyncingToSearch(static function () use ($changed): void {
            foreach ($changed as $entry) {
                $entry->save();
            }
        });

        // `$changed->searchable()` is a Scout collection MACRO, invisible to static analysis. This is
        // the exact method that macro delegates to, and it is a real method on the Searchable trait.
        $changed->first()->queueMakeSearchable($changed);
    }

    /**
     * Minutes an entry still occupies a server: a started in_service entry contributes only its
     * remaining duration; everything else contributes its full service duration.
     */
    private function workContributedBy(QueueEntry $entry): int
    {
        $service = $entry->service;
        $duration = $service === null ? 0 : (int) $service->duration_minutes;

        if ($entry->status === QueueEntryStatus::InService && $entry->started_at !== null) {
            $elapsed = (int) floor(CarbonImmutable::now()->diffInMinutes($entry->started_at, true));

            return max($duration - $elapsed, 0);
        }

        return $duration;
    }

    /** ceil(work / max(1, capacity)) — the documented formula, with no division by zero. */
    private function divide(int $work, int $capacity): int
    {
        return (int) ceil($work / max(1, $capacity));
    }

    /**
     * Count eligible personnel who are genuinely FREE for the target entry's service: active
     * lifecycle, same merchant + branch, schedule-available now, and not already in a live session.
     *
     * Constant four queries regardless of the eligible-personnel count.
     */
    private function availableCapacity(QueueEntry $target): int
    {
        // (1) eligibility
        $eligibleIds = ServicePersonnelEligibility::query()
            ->where('service_id', $target->service_id)
            ->where('active', true)
            ->pluck('staff_profile_id')
            ->all();

        if ($eligibleIds === []) {
            return 0;
        }

        // (2) staff in this merchant + branch, still employed
        $staff = StaffProfile::query()
            ->whereIn('id', $eligibleIds)
            ->where('merchant_id', $target->merchant_id)
            ->where('primary_branch_id', $target->branch_id)
            ->where('is_active', true)
            ->get();

        if ($staff->isEmpty()) {
            return 0;
        }

        /** @var list<int> $staffIds */
        $staffIds = $staff->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        // (3) every member's availability rows in one query, and (4) the busy set in one query.
        $rowsByStaff = $this->resolver->rowsForMany($staffIds);
        $busy = $this->projector->busyAmong($staffIds);

        return $staff
            ->filter(function (StaffProfile $member) use ($rowsByStaff, $busy): bool {
                if (isset($busy[(int) $member->id])) {
                    return false; // live session outranks the schedule — not capacity
                }

                return $this->resolver->currentState($member, null, $rowsByStaff[(int) $member->id] ?? null)
                    === PersonnelAvailabilityState::Available;
            })
            ->count();
    }
}
