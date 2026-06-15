<?php

declare(strict_types=1);

namespace App\Domain\Branches\Enums;

/** Branch calendar exception kinds (Plan §7.2, Scope §3.3). Mirrors the DB CHECK. */
enum CalendarExceptionType: string
{
    case PublicHoliday = 'public_holiday';
    case SpecialClosure = 'special_closure';
    case EmergencyClosure = 'emergency_closure';
    case ModifiedHours = 'modified_hours';
}
