<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\PersonnelAvailabilityState;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Scheduling\Models\ServiceSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Projects the LIVE personnel state (Plan §13.7, Scope §3.4; Phase 16C) by overlaying
 * `busy` onto the schedule-derived {@see AvailabilityResolver::currentState()} state.
 *
 * `busy` is DERIVED, never stored: a personnel member with an `in_progress` service
 * session is `busy`, which outranks the schedule-derived `available`/`on_break`/
 * `offline`/`unavailable`. Lifecycle `suspended` still outranks everything. Completing
 * or resolved-cancelling the session clears `busy` automatically (it is recomputed
 * from live sessions). A frontend toggle cannot override an active session — the
 * projection is server-derived.
 */
final class PersonnelStateProjector
{
    public function __construct(private readonly AvailabilityResolver $resolver) {}

    /**
     * @param  Collection<int, PersonnelAvailability>|null  $rows
     */
    public function currentState(
        StaffProfile $staff,
        ?CarbonInterface $now = null,
        ?Collection $rows = null,
    ): PersonnelAvailabilityState {
        $base = $this->resolver->currentState($staff, $now, $rows);

        // Lifecycle suspension outranks an active session.
        if ($base === PersonnelAvailabilityState::Suspended) {
            return $base;
        }

        return $this->hasActiveSession($staff)
            ? PersonnelAvailabilityState::Busy
            : $base;
    }

    /** Whether the personnel member has a live (in_progress) service session. */
    public function isBusy(StaffProfile $staff): bool
    {
        return $this->hasActiveSession($staff);
    }

    private function hasActiveSession(StaffProfile $staff): bool
    {
        return ServiceSession::query()
            ->where('staff_profile_id', $staff->id)
            ->where('status', ServiceSessionStatus::InProgress->value)
            ->exists();
    }
}
