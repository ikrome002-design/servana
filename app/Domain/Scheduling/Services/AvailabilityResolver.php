<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Enums\PersonnelAvailabilityState;
use App\Domain\Scheduling\Models\PersonnelAvailability;
use App\Domain\Scheduling\ValueObjects\TimeInterval;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * THE single deterministic availability resolver (Plan §13.7, §80 Phase 15B).
 *
 * No availability logic is duplicated in controllers, resources, stores, or tests.
 * Given a staff member's canonical recurring + exception rows, it answers two
 * questions for a business date in Africa/Nairobi:
 *
 *   1. is a proposed half-open interval fully available?  (scheduling gate)
 *   2. what is the derived current state?                 (read-only display)
 *
 * Layering / precedence (per the data dictionary):
 *   - exact-date EXCEPTION rows take precedence over RECURRING rows where they
 *     overlap; within one layer, UNAVAILABLE wins over AVAILABLE (a break/temporary
 *     unavailability subtracts time);
 *   - a weekday with no available recurring row is offline (day off);
 *   - `suspended` derives from staff lifecycle (is_active=false), never from rows;
 *   - `busy` is NOT computed here (queue/session aggregates — Phases 16B/16C).
 */
final class AvailabilityResolver
{
    private const GOV_AVAILABLE_EXCEPTION = 'available_exception';

    private const GOV_AVAILABLE_RECURRING = 'available_recurring';

    private const GOV_UNAVAILABLE_EXCEPTION = 'unavailable_exception';

    private const GOV_UNAVAILABLE_RECURRING = 'unavailable_recurring';

    private const GOV_NONE = 'none';

    /**
     * All canonical availability rows for the staff member. Filtered by
     * staff_profile_id (itself merchant/branch-scoped), so this is safe with or
     * without a resolved TenantContext.
     *
     * @return Collection<int, PersonnelAvailability>
     */
    public function rowsFor(StaffProfile $staff): Collection
    {
        return PersonnelAvailability::query()
            ->where('staff_profile_id', $staff->id)
            ->get();
    }

    /**
     * Canonical availability rows for MANY staff members in a single query, grouped by
     * `staff_profile_id` (Phase 24, PH24-QUEUE-001).
     *
     * Callers that resolve a set of personnel — the queue wait estimator's capacity count, for
     * example — must use this and pass each member's rows into {@see currentState()}/
     * {@see isIntervalAvailable()} via their `$rows` argument, instead of calling
     * {@see rowsFor()} once per member. Availability reads stay owned by this resolver either way.
     *
     * Missing members are returned as empty collections, so a caller never has to distinguish
     * "no rows" from "not fetched" (no rows correctly derives `offline`).
     *
     * @param  list<int>  $staffProfileIds
     * @return array<int, Collection<int, PersonnelAvailability>>
     */
    public function rowsForMany(array $staffProfileIds): array
    {
        if ($staffProfileIds === []) {
            return [];
        }

        $grouped = PersonnelAvailability::query()
            ->whereIn('staff_profile_id', $staffProfileIds)
            ->get()
            ->groupBy('staff_profile_id');

        $rows = [];
        foreach ($staffProfileIds as $id) {
            /** @var Collection<int, PersonnelAvailability> $forStaff */
            $forStaff = $grouped->get($id) ?? collect();
            $rows[$id] = $forStaff;
        }

        return $rows;
    }

    /**
     * Is the entire proposed half-open interval available on $businessDate?
     *
     * @param  Collection<int, PersonnelAvailability>|null  $rows  preloaded rows (avoids re-query)
     */
    public function isIntervalAvailable(
        StaffProfile $staff,
        CarbonInterface $businessDate,
        TimeInterval $proposed,
        ?Collection $rows = null,
    ): bool {
        $rows ??= $this->rowsFor($staff);
        $date = CarbonImmutable::parse($businessDate)->setTimezone($this->timezone());
        $weekday = $date->dayOfWeek; // 0=Sunday … 6=Saturday (matches branch_operating_hours)
        $dateKey = $date->format('Y-m-d');

        $relevant = $this->relevantRows($rows, $weekday, $dateKey);

        // Walk every elementary segment inside the proposed interval; each must be
        // governed by an AVAILABLE layer. Boundaries from relevant rows + the
        // proposal endpoints make midpoints unambiguous under half-open semantics.
        $boundaries = [$proposed->startSeconds, $proposed->endSeconds];
        foreach ($relevant as $row) {
            $start = TimeInterval::secondsFromString((string) $row->start_time);
            $end = TimeInterval::secondsFromString((string) $row->end_time);
            if ($start > $proposed->startSeconds && $start < $proposed->endSeconds) {
                $boundaries[] = $start;
            }
            if ($end > $proposed->startSeconds && $end < $proposed->endSeconds) {
                $boundaries[] = $end;
            }
        }
        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries);

        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            $midpoint = intdiv($boundaries[$i] + $boundaries[$i + 1], 2);
            $governance = $this->governanceAt($relevant, $midpoint);
            if (! in_array($governance, [self::GOV_AVAILABLE_EXCEPTION, self::GOV_AVAILABLE_RECURRING], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Derived, read-only current state.
     *
     * @param  Collection<int, PersonnelAvailability>|null  $rows
     */
    public function currentState(
        StaffProfile $staff,
        ?CarbonInterface $now = null,
        ?Collection $rows = null,
    ): PersonnelAvailabilityState {
        // Lifecycle outranks any schedule row.
        if (! $staff->is_active) {
            return PersonnelAvailabilityState::Suspended;
        }

        $rows ??= $this->rowsFor($staff);
        $moment = ($now !== null ? CarbonImmutable::parse($now) : CarbonImmutable::now())
            ->setTimezone($this->timezone());
        $weekday = $moment->dayOfWeek;
        $dateKey = $moment->format('Y-m-d');
        $second = ($moment->hour * 3600) + ($moment->minute * 60) + $moment->second;

        $relevant = $this->relevantRows($rows, $weekday, $dateKey);
        $governance = $this->governanceAt($relevant, $second);

        return match ($governance) {
            self::GOV_AVAILABLE_EXCEPTION, self::GOV_AVAILABLE_RECURRING => PersonnelAvailabilityState::Available,
            self::GOV_UNAVAILABLE_EXCEPTION => PersonnelAvailabilityState::Unavailable,
            self::GOV_UNAVAILABLE_RECURRING => $this->withinRecurringWorkingWindow($relevant, $second)
                ? PersonnelAvailabilityState::OnBreak
                : PersonnelAvailabilityState::Unavailable,
            default => PersonnelAvailabilityState::Offline,
        };
    }

    /**
     * Rows that apply to a specific business date: exception rows on that exact
     * date, plus recurring rows on that weekday.
     *
     * @param  Collection<int, PersonnelAvailability>  $rows
     * @return Collection<int, PersonnelAvailability>
     */
    private function relevantRows(Collection $rows, int $weekday, string $dateKey): Collection
    {
        return $rows->filter(function (PersonnelAvailability $row) use ($weekday, $dateKey): bool {
            if ($row->date !== null) {
                return $row->date->format('Y-m-d') === $dateKey;
            }

            return (int) $row->weekday === $weekday;
        })->values();
    }

    /**
     * Which layer governs a single second (exception over recurring; unavailable
     * over available within a layer).
     *
     * @param  Collection<int, PersonnelAvailability>  $relevant
     */
    private function governanceAt(Collection $relevant, int $second): string
    {
        $excUnavailable = false;
        $excAvailable = false;
        $recUnavailable = false;
        $recAvailable = false;

        foreach ($relevant as $row) {
            $start = TimeInterval::secondsFromString((string) $row->start_time);
            $end = TimeInterval::secondsFromString((string) $row->end_time);
            if ($second < $start || $second >= $end) {
                continue; // half-open membership
            }

            $isException = $row->date !== null;
            if ($isException && ! $row->available) {
                $excUnavailable = true;
            } elseif ($isException) {
                $excAvailable = true;
            } elseif (! $row->available) {
                $recUnavailable = true;
            } else {
                $recAvailable = true;
            }
        }

        if ($excUnavailable) {
            return self::GOV_UNAVAILABLE_EXCEPTION;
        }
        if ($excAvailable) {
            return self::GOV_AVAILABLE_EXCEPTION;
        }
        if ($recUnavailable) {
            return self::GOV_UNAVAILABLE_RECURRING;
        }
        if ($recAvailable) {
            return self::GOV_AVAILABLE_RECURRING;
        }

        return self::GOV_NONE;
    }

    /**
     * True when a recurring AVAILABLE interval contains the second — i.e. the
     * governing recurring-unavailable row is a scheduled break, not a day off.
     *
     * @param  Collection<int, PersonnelAvailability>  $relevant
     */
    private function withinRecurringWorkingWindow(Collection $relevant, int $second): bool
    {
        foreach ($relevant as $row) {
            if ($row->date !== null || ! $row->available) {
                continue;
            }
            $start = TimeInterval::secondsFromString((string) $row->start_time);
            $end = TimeInterval::secondsFromString((string) $row->end_time);
            if ($second >= $start && $second < $end) {
                return true;
            }
        }

        return false;
    }

    private function timezone(): string
    {
        return (string) config('servana.scheduling.business_timezone', 'Africa/Nairobi');
    }
}
