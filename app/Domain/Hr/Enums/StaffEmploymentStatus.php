<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

/** Staff employment status (Plan §7.1, Scope §3.4). Mirrors the DB CHECK. */
enum StaffEmploymentStatus: string
{
    case Employed = 'employed';
    case OnLeave = 'on_leave';
    case Terminated = 'terminated';
}
