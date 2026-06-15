<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Branches\Models\BranchOperatingHour;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Operating-hour authority (Plan §10.3 `branch.profile.manage`). Object-level
 * companion to MerchantBranchPolicy::manageOperatingHours; gates by branch scope
 * + the branch-profile capability. Branch routes bind the parent branch, so this
 * is used where an operating-hour row itself is the subject.
 */
final class BranchOperatingHourPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user, BranchOperatingHour $hour): bool
    {
        return $this->context->canAccessBranch($hour->branch_id);
    }

    public function manage(User $user, BranchOperatingHour $hour): bool
    {
        return $this->context->canAccessBranch($hour->branch_id)
            && $this->context->can('branch.profile.manage');
    }
}
