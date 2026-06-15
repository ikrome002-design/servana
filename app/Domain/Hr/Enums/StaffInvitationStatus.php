<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

/** Staff invitation lifecycle (Plan §7.1, Scope §3.4). Mirrors the DB CHECK. */
enum StaffInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
