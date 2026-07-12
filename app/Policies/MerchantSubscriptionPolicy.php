<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Merchant subscription self-service authority (Plan §22, §48; §19.3; Phase 20B). Merchant
 * Administrator, merchant scope. `merchant.subscription.view` reads the subscription/dashboard,
 * plan options, and scheduled change; `merchant.subscription.plan_change` schedules/cancels a
 * no-proration next-cycle change (the route additionally enforces the billing-mutable gate). No
 * merchant/branch ownership arg is needed — tenant isolation is enforced by the BelongsToMerchant
 * query scope + tenant-safe route binding. Defence-in-depth alongside the route `EnsurePermission`.
 */
final class MerchantSubscriptionPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user): bool
    {
        return $this->context->can('merchant.subscription.view');
    }

    public function scheduleChange(User $user): bool
    {
        return $this->context->can('merchant.subscription.plan_change');
    }
}
