<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Membership authority + the HR anti-self-escalation rule (Plan §10.2).
 *
 * Permission overrides are managed by Merchant Admin (any membership in its
 * merchant) and by HR (operational staff in its own branch). HR can NEVER alter
 * its own membership and can never grant a target a capability HR does not itself
 * hold — the self-escalation guard (Plan §10.2: "policy denies role changes where
 * target = self or target role outranks actor").
 */
final class MerchantUserPolicy
{
    /** Roles HR may administer (operational staff only — never admin/manager/hr). */
    private const HR_MANAGEABLE_ROLES = [
        MerchantUserRole::Finance,
        MerchantUserRole::FrontOffice,
        MerchantUserRole::Personnel,
        MerchantUserRole::Audit,
    ];

    public function __construct(private readonly TenantContext $context) {}

    /** View the resolved/preview permissions of a membership. */
    public function viewPermissions(User $user, MerchantUser $target): bool
    {
        if ($target->merchant_id !== $this->context->merchantId()) {
            return false;
        }

        return $this->context->can('branches.manage_users_lifecycle')
            || $this->canHrManage($target);
    }

    /** Create/update/revoke a permission override on a membership. */
    public function managePermissions(User $user, MerchantUser $target): bool
    {
        if ($target->merchant_id !== $this->context->merchantId()) {
            return false;
        }

        // Nobody may edit their OWN membership's permissions (anti-self-escalation).
        if ($target->user_id === $user->id) {
            return false;
        }

        if ($this->context->can('branches.manage_users_lifecycle')) {
            return true;
        }

        return $this->canHrManage($target);
    }

    /**
     * Whether HR (staff lifecycle authority) may administer this target: an
     * operational role sharing at least one branch with HR's own scope, with no
     * branch outside that scope.
     */
    private function canHrManage(MerchantUser $target): bool
    {
        if (! $this->context->can('staff.suspend')) {
            return false;
        }

        if (! in_array($target->role, self::HR_MANAGEABLE_ROLES, true)) {
            return false;
        }

        $branchIds = $target->activeBranchIds();
        if ($branchIds === []) {
            return false;
        }

        foreach ($branchIds as $branchId) {
            if (! $this->context->canAccessBranch($branchId)) {
                return false;
            }
        }

        return true;
    }
}
