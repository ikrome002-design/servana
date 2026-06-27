<?php

declare(strict_types=1);

namespace App\Domain\Files\Enums;

/** Storage lifecycle of an uploaded file (Plan §65; Phase 10F). */
enum FileLifecycleStatus: string
{
    case Quarantined = 'quarantined';
    case Available = 'available';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case Deleted = 'deleted';
}
