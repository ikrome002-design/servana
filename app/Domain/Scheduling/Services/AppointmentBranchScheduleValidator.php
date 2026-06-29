<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Branches\Enums\CalendarExceptionType;
use App\Domain\Branches\Models\BranchCalendarException;
use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Scheduling\Exceptions\AppointmentBranchScheduleException;
use App\Domain\Scheduling\ValueObjects\TimeInterval;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * THE single reusable branch operating-calendar gate for appointments (Plan §36;
 * Phase 16A). It validates an appointment interval AROUND the shared
 * {@see PersonnelSchedulingValidator} (which covers personnel eligibility +
 * availability). It is the only place branch-hours/calendar logic lives — no
 * controller, request, Resource, or other action duplicates it.
 *
 * For a given interval it asserts, in `Africa/Nairobi` business time:
 *   - the branch lifecycle is active;
 *   - the interval is a single business date with start < end;
 *   - calendar exceptions for that exact date are applied
 *     (public-holiday / special-closure / emergency-closure → closed;
 *     modified-hours → the modified window);
 *   - otherwise the weekly operating hours for that weekday apply (a closed
 *     weekday → closed);
 *   - the FULL interval sits inside the open window and does not cross a closed
 *     sub-period (operating-hours break).
 *
 * Future-dated appointments validate the operating calendar for the APPOINTMENT
 * date; the branch's current-day open state is NOT required to schedule a future
 * appointment (same-day check-in enforces the Branch Day machine separately).
 */
final class AppointmentBranchScheduleValidator
{
    public function ensure(MerchantBranch $branch, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        $tz = (string) config('servana.scheduling.business_timezone', 'Africa/Nairobi');
        $start = CarbonImmutable::parse($startsAt)->setTimezone($tz);
        $end = CarbonImmutable::parse($endsAt)->setTimezone($tz);

        if (! $branch->isActive()) {
            throw AppointmentBranchScheduleException::branchInactive();
        }

        if (! $start->isSameDay($end)) {
            throw AppointmentBranchScheduleException::invalidWindow();
        }

        try {
            $interval = TimeInterval::fromStrings($start->format('H:i:s'), $end->format('H:i:s'));
        } catch (InvalidArgumentException) {
            throw AppointmentBranchScheduleException::invalidWindow();
        }

        $dateKey = $start->format('Y-m-d');
        $weekday = $start->dayOfWeek; // 0=Sunday … 6=Saturday (branch_operating_hours convention)

        [$window, $closedPeriods] = $this->openWindowFor($branch, $dateKey, $weekday);

        // The full interval must sit inside the open window.
        if ($interval->startSeconds < $window->startSeconds || $interval->endSeconds > $window->endSeconds) {
            throw AppointmentBranchScheduleException::outsideHours();
        }

        // The interval must not cross any closed sub-period (operating-hours break).
        foreach ($closedPeriods as $closed) {
            if ($interval->overlaps($closed)) {
                throw AppointmentBranchScheduleException::crossesClosedPeriod();
            }
        }
    }

    /**
     * Resolve the open window + closed sub-periods for a business date. An
     * exact-date exception overrides the weekly hours; a closure type or a closed
     * weekday throws `branch_closed`.
     *
     * @return array{0: TimeInterval, 1: list<TimeInterval>}
     */
    private function openWindowFor(MerchantBranch $branch, string $dateKey, int $weekday): array
    {
        $exception = $branch->calendarExceptions()
            ->whereDate('date', $dateKey)
            ->first();

        if ($exception instanceof BranchCalendarException) {
            return $this->windowFromException($exception);
        }

        $hours = $branch->operatingHours()
            ->where('weekday', $weekday)
            ->first();

        if (! $hours instanceof BranchOperatingHour
            || $hours->is_closed
            || $hours->opens_at === null
            || $hours->closes_at === null) {
            throw AppointmentBranchScheduleException::branchClosed();
        }

        $window = $this->interval((string) $hours->opens_at, (string) $hours->closes_at);
        $closed = [];

        if ($hours->break_start !== null && $hours->break_end !== null) {
            $break = $this->interval((string) $hours->break_start, (string) $hours->break_end);
            if ($break !== null) {
                $closed[] = $break;
            }
        }

        if ($window === null) {
            throw AppointmentBranchScheduleException::branchClosed();
        }

        return [$window, $closed];
    }

    /**
     * @return array{0: TimeInterval, 1: list<TimeInterval>}
     */
    private function windowFromException(BranchCalendarException $exception): array
    {
        $closureTypes = [
            CalendarExceptionType::PublicHoliday,
            CalendarExceptionType::SpecialClosure,
            CalendarExceptionType::EmergencyClosure,
        ];

        if (in_array($exception->type, $closureTypes, true)) {
            throw AppointmentBranchScheduleException::branchClosed();
        }

        // ModifiedHours: must define an open window, else it is effectively closed.
        if ($exception->opens_at === null || $exception->closes_at === null) {
            throw AppointmentBranchScheduleException::branchClosed();
        }

        $window = $this->interval((string) $exception->opens_at, (string) $exception->closes_at);

        if ($window === null) {
            throw AppointmentBranchScheduleException::branchClosed();
        }

        return [$window, []];
    }

    private function interval(string $start, string $end): ?TimeInterval
    {
        try {
            return TimeInterval::fromStrings($start, $end);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
