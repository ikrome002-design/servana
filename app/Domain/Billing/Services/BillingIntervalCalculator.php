<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\BillingInterval;
use Carbon\CarbonImmutable;

/**
 * Canonical subscription interval date math (Plan §49; Phase 20B). The SOLE date-math source
 * for subscription period boundaries, next-cycle plan changes, invoice periods, interval-derived
 * due boundaries, and escalation period boundaries. No other class may duplicate this math.
 *
 * All computation is done in `Africa/Nairobi` on calendar dates (day granularity), so results are
 * DST-independent — we add calendar days / months / years, never fixed hour spans.
 *
 *   - weekly      → +7 calendar days
 *   - bi_weekly   → +14 calendar days
 *   - monthly     → +1 calendar month, end-of-month clamp
 *   - quarterly   → +3 calendar months, same clamp
 *   - annual      → +1 calendar year (=+12 months), leap-year clamp (Feb 29 → Feb 28)
 *
 * The billing ANCHOR day (the subscription's issuance day-of-month) is preserved across periods
 * and clamped only to the shortest month, so e.g. an anchor of the 31st yields
 * Jan 31 → Feb 28 → Mar 31 → Apr 30 → … without drift. Month-based boundaries are always computed
 * from the target month's first day plus the anchor day, never by re-clamping a previously clamped
 * date (which would drift Feb 28 → Mar 28).
 */
final class BillingIntervalCalculator
{
    public const TIMEZONE = 'Africa/Nairobi';

    /**
     * The next boundary after `$from` for `$interval`, preserving `$anchorDay`
     * (defaults to `$from`'s day-of-month for the first period).
     */
    public function nextBoundary(CarbonImmutable $from, BillingInterval $interval, ?int $anchorDay = null): CarbonImmutable
    {
        $from = $from->setTimezone(self::TIMEZONE)->startOfDay();
        $anchorDay ??= $from->day;

        return match ($interval) {
            BillingInterval::Weekly => $from->addDays(7),
            BillingInterval::BiWeekly => $from->addDays(14),
            BillingInterval::Monthly => $this->addMonths($from, 1, $anchorDay),
            BillingInterval::Quarterly => $this->addMonths($from, 3, $anchorDay),
            BillingInterval::Annual => $this->addMonths($from, 12, $anchorDay),
        };
    }

    /**
     * Trial-end instant: `$anchor` (Merchant-Admin creation time, Gate B1) + `$trialDays` calendar
     * days. This returns a timestamp INSTANT (stored in a timestamptz column), so it must not be
     * timezone-shifted — adding calendar days preserves the wall-clock, so the Nairobi date advances
     * by exactly `$trialDays` while the stored instant stays correct.
     */
    public function trialEnd(CarbonImmutable $anchor, int $trialDays): CarbonImmutable
    {
        return $anchor->addDays($trialDays);
    }

    /** The Africa/Nairobi calendar date of an instant, as a start-of-day CarbonImmutable. */
    public function nairobiDate(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->setTimezone(self::TIMEZONE)->startOfDay();
    }

    /**
     * Add whole calendar months from the target month's first day, then clamp the anchor day to
     * the target month length. Computing from the first day (never a previously clamped date)
     * makes the result drift-free and leap-safe.
     */
    private function addMonths(CarbonImmutable $from, int $months, int $anchorDay): CarbonImmutable
    {
        $target = $from->startOfMonth()->addMonths($months);
        $day = min($anchorDay, $target->daysInMonth);

        return $target->setDay($day);
    }
}
