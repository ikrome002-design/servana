<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Staff roster + lifecycle authority (Plan §10.2/§10.3, Scope §3.4).
 *
 * READ and MANAGE are distinct authorities (Phase 23 security remediation). Before
 * Phase 23 the roster READ had no authority at all: `view` simply delegated to
 * `manage`, `StaffController::index` called neither, and `GET /api/v1/staff` carried
 * no permission middleware — so any authenticated merchant member could enumerate
 * the branch roster including personnel phone numbers (Plan §9.1 personnel-contact
 * extraction; RK-05).
 *
 *   - READ (`viewAny`/`view`): the canonical §19.3 `staff.view`, HR-only, branch-scoped.
 *     The Branch Manager is deliberately NOT granted it — its read-only personnel
 *     picker is served by the narrow `branch.personnel-options.index` endpoint under
 *     the existing `branch.dashboard.view`.
 *   - MANAGE: unchanged. HR manages OPERATIONAL staff within its own branch scope
 *     (`staff.suspend`); Merchant Admin manages the branch_manager/hr it added,
 *     merchant-wide (`branches.manage_users_lifecycle`). A read key never implies a
 *     mutation, and `manage` never substitutes for `view`.
 *
 * Cross-merchant staff must not leak — every method re-checks the merchant even
 * though the controller 404s a foreign merchant_id first (defence in depth).
 */
final class StaffProfilePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Collection read (`GET /api/v1/staff`). Branch scope is applied by the query's
     * BelongsToBranch/BelongsToMerchant scopes; this proves the caller's authority.
     */
    public function viewAny(User $user): bool
    {
        return $this->context->can('staff.view');
    }

    /** Record read (`GET /api/v1/staff/{staff}`) — `staff.view` within branch scope. */
    public function view(User $user, StaffProfile $staff): bool
    {
        return $this->context->can('staff.view')
            && $staff->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($staff->primary_branch_id);
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
