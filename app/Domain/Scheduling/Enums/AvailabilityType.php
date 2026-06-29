<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Enums;

/**
 * personnel_availability.type (Plan §13.7; Phase 15B). Mirrors the DB CHECK.
 *
 *   - Recurring: a weekly row (weekday set, date null).
 *   - Exception: an exact-business-date row (date set, weekday null), taking
 *     precedence over recurring rows for the same interval.
 */
enum AvailabilityType: string
{
    case Recurring = 'recurring';
    case Exception = 'exception';
}
