<?php

declare(strict_types=1);

namespace App\Domain\Auth\Enums;

/**
 * A per-membership override either adds a grantable capability (`Grant`) or
 * removes one (`Deny`). Deny always beats Grant during resolution (Plan §10.3).
 */
enum PermissionOverrideEffect: string
{
    case Grant = 'grant';
    case Deny = 'deny';
}
