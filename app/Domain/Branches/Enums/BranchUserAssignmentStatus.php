<?php

declare(strict_types=1);

namespace App\Domain\Branches\Enums;

/** Branch assignment lifecycle (Plan §7.1). Mirrors the DB CHECK. */
enum BranchUserAssignmentStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
