<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Auth\Services\PermissionResolver;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Sessions\Support\SessionBinding;
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

    /**
     * @param  SessionBinding|null  $binding  which account the CURRENT session is bound to, already
     *                                        verified by the session/host boundary (Phase UI-03).
     *                                        Null means the caller has no binding to offer and the
     *                                        previous unbound behaviour applies.
     */
    public function populate(TenantContext $context, ?User $user, ?SessionBinding $binding = null): void
    {
        // Rebuild from scratch so a reused (scoped) instance never carries stale
        // state from a previous request/resolution.
        $context->reset();

        if ($user === null) {
            return;
        }

        if ($user->is_platform_staff) {
            $context->markPlatformStaff();
            // The user id lets the resolver subtract this administrator's deny overrides
            // (COR-UI08-001). Platform authority is still the super_admin role defaults minus denies.
            $context->setPermissions($this->permissions->forPlatformStaff($user->id));

            return;
        }

        /*
         * WHICH membership is this request operating as?
         *
         * `activeMembership()` returns the FIRST active membership, which is the only possible
         * answer for the overwhelming majority of users — they hold exactly one. It is the wrong
         * answer for a multi-merchant user: `merchant_users` is UNIQUE(merchant, user), so holding
         * two memberships means holding them in two different MERCHANTS, and picking the first one
         * makes the tenant context independent of which account the session is actually in.
         *
         * That is precisely what the UI-03 deployed-origin browser proof caught. After a context
         * handoff to the Audit account of merchant B, `/api/v1/me` on `audit.servana.ke` still
         * reported merchant A and merchant A's Front Office permissions, because nothing here
         * consulted the server-created `host_sessions` binding that the switch had just written.
         *
         * So the caller may hand us the membership the session is BOUND to. It is still verified
         * here — belongs to this user, still active, merchant still loadable — because a binding is
         * an identifier for the intended context, never a grant. Permissions are resolved fresh
         * from that membership on every request either way; nothing is ever carried across.
         */
        if ($binding !== null && $binding->failsClosed()) {
            // A binding was required and did not hold. Leave the context empty and let the
            // downstream gates deny; never substitute another membership the user happens to hold.
            return;
        }

        $membership = $binding !== null && $binding->membership !== null
            ? ($this->isUsableFor($binding->membership, $user) ? $binding->membership : null)
            : ($binding === null || $binding->fallsBack() ? $user->activeMembership() : null);

        if ($membership !== null && $membership->merchant !== null) {
            $context->setMerchant($membership->merchant, $membership);
            $context->setPermissions($this->permissions->forMembership($membership));
        }
    }

    /**
     * A bound membership is usable only if it still belongs to this user and is still active.
     *
     * When it is not, the context stays EMPTY rather than silently falling back to some other
     * membership the user happens to hold: falling back would hand a request addressed to one
     * account the authority of another, which is the whole defect this guard exists to prevent.
     */
    private function isUsableFor(MerchantUser $membership, User $user): bool
    {
        return $membership->user_id === $user->id
            && $membership->status === MerchantUserStatus::Active;
    }
}
