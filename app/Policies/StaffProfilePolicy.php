<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Staff roster + lifecycle authority (Plan §10.2/§10.3, Scope §3.4).
 *
 * Two distinct authorities manage staff, by capability (never by raw role):
 *   - HR manages OPERATIONAL staff within its own branch scope (`staff.suspend`).
 *   - Merchant Admin manages the branch_manager/hr it added, merchant-wide
 *     (`branches.manage_users_lifecycle`).
 *
 * Cross-merchant staff must not leak — `manage`/`view` return false here only
 * after the controller has 404'd a foreign merchant_id; the same-merchant guard
 * is retained as defence-in-depth.
 */
final class StaffProfilePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user, StaffProfile $staff): bool
    {
        return $this->manage($user, $staff);
    }

    public function manage(User $user, StaffProfile $staff): bool
    {
        if ($staff->merchant_id !== $this->context->merchantId()) {
            return false;
        }

        // Merchant Admin: branch-user lifecycle, merchant-wide.
        if ($this->context->can('branches.manage_users_lifecycle')) {
            return true;
        }

        // HR: operational staff lifecycle within its own branch scope.
        return $this->context->can('staff.suspend')
            && $this->context->canAccessBranch($staff->primary_branch_id);
    }
}
