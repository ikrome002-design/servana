<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\User;

/**
 * Populates a TenantContext from a user (Plan §8.1).
 *
 * Single source of truth for "given this user, what is their tenant context?",
 * shared by ResolveTenantContext (the middleware, on every authenticated
 * request) and the Magic Link verify controller (which logs the user in outside
 * the middleware group and still needs the bootstrap to carry merchant context).
 */
final class TenantContextResolver
{
    public function populate(TenantContext $context, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        if ($user->is_platform_staff) {
            $context->markPlatformStaff();

            return;
        }

        $membership = $user->activeMembership();

        if ($membership !== null && $membership->merchant !== null) {
            $context->setMerchant($membership->merchant, $membership);
        }
    }
}
