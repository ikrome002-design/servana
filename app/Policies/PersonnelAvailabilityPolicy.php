<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Personnel availability authority (Plan §19.3, §80 Phase 15B).
 *
 * HR owns mutation within its own branch scope (`personnel.availability.manage`).
 * The Branch Manager has branch-scoped READ-ONLY visibility via the existing
 * `branch.dashboard.view` and may never mutate. Merchant Admin holds neither key,
 * so it has no default availability authority. Cross-merchant staff are 404'd at
 * route binding; same-merchant out-of-branch staff are filtered by the StaffProfile
 * BranchScope (404) — branch scope is re-checked here as defence-in-depth.
 */
final class PersonnelAvailabilityPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    /** Read: HR (manage) OR Branch Manager (branch dashboard), within branch scope. */
    public function view(User $user, StaffProfile $staff): bool
    {
        return ($this->context->can('personnel.availability.manage') || $this->context->can('branch.dashboard.view'))
            && $staff->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($staff->primary_branch_id);
    }

    /** Mutate: HR only, within its own branch scope. */
    public function manage(User $user, StaffProfile $staff): bool
    {
        return $this->context->can('personnel.availability.manage')
            && $staff->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($staff->primary_branch_id);
    }
}
