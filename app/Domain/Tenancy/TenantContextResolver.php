<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Auth\Services\PermissionResolver;
use App\Models\User;

/**
 * Populates a TenantContext from a user (Plan §8.1, §10.3).
 *
 * Single source of truth for "given this user, what is their tenant context?",
 * shared by ResolveTenantContext (the middleware, on every authenticated
 * request) and the Magic Link verify controller (which logs the user in outside
 * the middleware group and still needs the bootstrap to carry merchant context).
 *
 * Phase 8: also resolves the request-cached permission set (PermissionResolver)
 * so /me, EnsurePermission, and policies all read one consistent view.
 */
final class TenantContextResolver
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function populate(TenantContext $context, ?User $user): void
    {
        // Rebuild from scratch so a reused (scoped) instance never carries stale
        // state from a previous request/resolution.
        $context->reset();

        if ($user === null) {
            return;
        }

        if ($user->is_platform_staff) {
            $context->markPlatformStaff();
            $context->setPermissions($this->permissions->forPlatformStaff());

            return;
        }

        $membership = $user->activeMembership();

        if ($membership !== null && $membership->merchant !== null) {
            $context->setMerchant($membership->merchant, $membership);
            $context->setPermissions($this->permissions->forMembership($membership));
        }
    }
}
