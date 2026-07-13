<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Billing\Models\PlatformFeeDispute;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Percentage platform-fee dispute authority (Plan §13.10 [Correction 3], §19.3; Phase 20E). Merchant
 * scope. `platform_fee.dispute` raises a dispute (Merchant Admin/Finance; blocked in billing read-only);
 * `platform_fee.view` reads the scoped list/detail; `platform_fee.dispute.review` reviews/resolves/rejects
 * (Finance; fresh step-up on resolve/reject; a money change creates an additive adjustment). The dispute
 * creator may not resolve/reject their own dispute (enforced in the action). Tenant isolation is enforced
 * by the BelongsToMerchant scope + tenant-safe route binding. Defence-in-depth alongside route
 * `EnsurePermission`.
 */
final class PlatformFeeDisputePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('platform_fee.view');
    }

    public function view(User $user, PlatformFeeDispute $dispute): bool
    {
        return $this->context->can('platform_fee.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('platform_fee.dispute');
    }

    public function review(User $user, PlatformFeeDispute $dispute): bool
    {
        return $this->context->can('platform_fee.dispute.review');
    }
}
