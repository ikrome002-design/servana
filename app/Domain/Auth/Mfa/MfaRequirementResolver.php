<?php

declare(strict_types=1);

namespace App\Domain\Auth\Mfa;

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Models\User;

/**
 * Decides whether MFA is mandatory for a user (Plan §18; Phase R3).
 *
 * Resolves from the user's CURRENT platform status and ACTIVE memberships —
 * deliberately WITHOUT a TenantContext, because mandatory MFA is checked
 * immediately after authentication and *before* tenant context is resolved
 * (Plan §9.4 step 2 / §18 enforcement order).
 *
 * Mandatory roles (Plan §18):
 *   - platform Super Administrator  → `users.is_platform_staff = true`
 *     (the as-built model has exactly one platform role, Super Administrator,
 *     materialised by `is_platform_staff`; PermissionResolver::forPlatformStaff
 *     maps it to the super_admin grant set).
 *   - active `merchant_admin` membership → required
 *   - active `finance` membership → required
 *
 * Multiple memberships are handled safely: ANY active mandatory membership makes
 * MFA required. All other roles are not mandatory and keep passwordless-only
 * auth unless they voluntarily enroll.
 */
final class MfaRequirementResolver
{
    /** @var list<string> */
    private const MANDATORY_ROLES = [
        MerchantUserRole::MerchantAdmin->value,
        MerchantUserRole::Finance->value,
    ];

    public function isRequired(User $user): bool
    {
        // Platform Super Administrator.
        if ($user->is_platform_staff) {
            return true;
        }

        // Any active merchant_admin / finance membership.
        return $user->merchantUsers()
            ->active()
            ->whereIn('role', self::MANDATORY_ROLES)
            ->exists();
    }
}
