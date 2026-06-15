<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Branch authority (Plan §10.2, §10.3). Structural lifecycle (create/archive) is
 * Merchant Admin (`branches.create`); profile/hours editing is Branch Manager
 * (`branch.profile.manage`); day open/close is `day.open_close`. Cross-merchant
 * branches never reach these methods — route binding + EnsureBranchScope 404
 * first (no existence leak) — so a same-merchant check is defence-in-depth.
 */
final class MerchantBranchPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasMerchant();
    }

    public function view(User $user, MerchantBranch $branch): bool
    {
        return $this->sameMerchant($branch) && $this->context->canAccessBranch($branch->id);
    }

    public function create(User $user): bool
    {
        return $this->context->can('branches.create');
    }

    public function update(User $user, MerchantBranch $branch): bool
    {
        return $this->sameMerchant($branch)
            && $this->context->canAccessBranch($branch->id)
            && $this->context->can('branch.profile.manage');
    }

    public function archive(User $user, MerchantBranch $branch): bool
    {
        return $this->sameMerchant($branch) && $this->context->can('branches.create');
    }

    public function manageOperatingHours(User $user, MerchantBranch $branch): bool
    {
        return $this->update($user, $branch);
    }

    public function manageDay(User $user, MerchantBranch $branch): bool
    {
        return $this->sameMerchant($branch)
            && $this->context->canAccessBranch($branch->id)
            && $this->context->can('day.open_close');
    }

    private function sameMerchant(MerchantBranch $branch): bool
    {
        return $branch->merchant_id === $this->context->merchantId();
    }
}
