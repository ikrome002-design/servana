<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Catalogue\Models\ServicePersonnelEligibility;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use Illuminate\Support\Collection;

/**
 * THE single deterministic next-available personnel selector (Plan §37; Phase 16B).
 *
 * Candidates must belong to the same merchant, have an active branch assignment to
 * the entry's branch, be active personnel, be eligible for the selected service, be
 * effectively available, not be suspended, and not already be in an active
 * conflicting queue/service state — all delegated to
 * {@see QueuePersonnelAssignmentValidator} (which reuses the Phase 15B scheduling
 * services; no eligibility/availability duplication here).
 *
 * Deterministic ordering:
 *   1. lowest count of active assigned/called/in-service queue work (load);
 *   2. earliest last queue assignment (never-assigned first);
 *   3. staff-profile ULID as the stable final tie-break.
 */
final class NextAvailablePersonnelSelector
{
    public function __construct(private readonly QueuePersonnelAssignmentValidator $validator) {}

    public function select(QueueEntry $entry): ?StaffProfile
    {
        $eligibleIds = ServicePersonnelEligibility::query()
            ->where('service_id', $entry->service_id)
            ->where('active', true)
            ->pluck('staff_profile_id')
            ->all();

        if ($eligibleIds === []) {
            return null;
        }

        /** @var Collection<int, StaffProfile> $candidates */
        $candidates = StaffProfile::query()
            ->whereIn('id', $eligibleIds)
            ->where('merchant_id', $entry->merchant_id)
            ->where('primary_branch_id', $entry->branch_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (StaffProfile $staff): bool => $this->validator->isAssignable($entry, $staff))
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $ranked = $candidates
            ->map(fn (StaffProfile $staff): array => [
                'staff' => $staff,
                'load' => $this->activeLoad($staff->id),
                'last_assigned' => $this->lastAssignedAt($staff->id),
                'ulid' => $staff->ulid,
            ])
            ->sort(function (array $a, array $b): int {
                if ($a['load'] !== $b['load']) {
                    return $a['load'] <=> $b['load'];
                }

                // Never-assigned (null) sorts before any timestamp; then earliest.
                if ($a['last_assigned'] !== $b['last_assigned']) {
                    if ($a['last_assigned'] === null) {
                        return -1;
                    }
                    if ($b['last_assigned'] === null) {
                        return 1;
                    }

                    return $a['last_assigned'] <=> $b['last_assigned'];
                }

                return strcmp($a['ulid'], $b['ulid']);
            })
            ->values();

        /** @var array{staff: StaffProfile, load: int, last_assigned: ?string, ulid: string} $top */
        $top = $ranked->first();

        return $top['staff'];
    }

    private function activeLoad(int $staffProfileId): int
    {
        return QueueEntry::query()
            ->where('staff_profile_id', $staffProfileId)
            ->whereIn('status', QueueEntry::statusValues([
                QueueEntryStatus::Assigned,
                QueueEntryStatus::Called,
                QueueEntryStatus::InService,
            ]))
            ->count();
    }

    private function lastAssignedAt(int $staffProfileId): ?string
    {
        $value = QueueEntry::query()
            ->where('staff_profile_id', $staffProfileId)
            ->whereNotNull('assigned_at')
            ->max('assigned_at');

        return $value === null ? null : (string) $value;
    }
}
