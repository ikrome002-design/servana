<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

/** Append-only staff_history field kinds (Plan §7.1, Scope §3.4). Mirrors the DB CHECK. */
enum StaffHistoryField: string
{
    case Role = 'role';
    case Branch = 'branch';
    case Status = 'status';
    case EmploymentStatus = 'employment_status';
    case ServiceEligibility = 'service_eligibility';
    case Availability = 'availability';
}
