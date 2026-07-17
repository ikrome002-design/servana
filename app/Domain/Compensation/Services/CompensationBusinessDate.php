<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The compensation business date (Plan §59; Phase 20F, F8). Timestamps are UTC, but every
 * effective-date decision — is this change backdated? has a scheduled plan reached its boundary? —
 * is made against the **`Africa/Nairobi`** business day (CLAUDE.md §1).
 *
 * Single source so backdating detection and boundary activation can never disagree.
 */
final class CompensationBusinessDate
{
    public const TIMEZONE = 'Africa/Nairobi';

    /** Today's business date in Africa/Nairobi, normalized to midnight. */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    /**
     * Normalize any stored/inbound date to a comparable Africa/Nairobi business day. Accepts any
     * CarbonInterface (the framework's `today()`/`now()` return the MUTABLE Carbon) or a date
     * string, and always returns an immutable, timezone-correct business day.
     */
    public function normalize(CarbonInterface|string $date): CarbonImmutable
    {
        if ($date instanceof CarbonInterface) {
            // Reduce to the calendar date first: a UTC timestamp must not shift the business day.
            $date = $date->toDateString();
        }

        return CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
    }

    /**
     * F8: a change is backdated when it takes effect BEFORE the current business date. An
     * effective_from of today is NOT backdated.
     */
    public function isBackdated(CarbonInterface|string $effectiveFrom): bool
    {
        return $this->normalize($effectiveFrom)->lessThan($this->today());
    }

    /** True once a scheduled effective_from has been reached (today or earlier). */
    public function hasReached(CarbonInterface|string $effectiveFrom): bool
    {
        return $this->normalize($effectiveFrom)->lessThanOrEqualTo($this->today());
    }
}
