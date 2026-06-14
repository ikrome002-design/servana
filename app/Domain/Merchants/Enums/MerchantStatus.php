<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Enums;

/**
 * Merchant tenant lifecycle (Plan §7.1, Scope §3.2/§5.1).
 *
 * Mirrors the merchants.status DB CHECK. A merchant is created `PendingSetup`,
 * becomes `Active` when first-time setup completes, and may later be `Suspended`
 * or `Deactivated` by Super Admin governance (a later phase).
 */
enum MerchantStatus: string
{
    case PendingSetup = 'pending_setup';
    case Active = 'active';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';

    /** Merchants that may reach the operational dashboard (EnsureMerchantActive). */
    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isPendingSetup(): bool
    {
        return $this === self::PendingSetup;
    }
}
