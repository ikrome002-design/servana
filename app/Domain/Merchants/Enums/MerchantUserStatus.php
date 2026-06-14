<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Enums;

/**
 * Membership lifecycle (Plan §7.1, §8.1).
 *
 * Mirrors the merchant_users.status DB CHECK. Only an `Active` row grants
 * access (eligibility check 4 / authorization). The registering owner is
 * `Active` immediately; initial staff added during setup are `Invited` until
 * Phase 7's accept flow activates them.
 */
enum MerchantUserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
