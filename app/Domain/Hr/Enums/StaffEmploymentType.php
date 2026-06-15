<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

/** Staff employment type (Plan §7.1, Scope §3.4). Mirrors the DB CHECK. */
enum StaffEmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';
    case CommissionOnly = 'commission_only';
}
