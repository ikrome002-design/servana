<?php

declare(strict_types=1);

namespace App\Domain\Branches\Enums;

/**
 * Branch lifecycle (Plan §7.2). Mirrors the merchant_branches.status DB CHECK.
 *
 * Phase 6 only ever creates `Active` branches during first-time setup; the
 * suspend/archive transitions and closure-protection rules are Phase 7.
 */
enum BranchStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';
}
