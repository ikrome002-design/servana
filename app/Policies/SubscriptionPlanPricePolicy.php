<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Billing\Models\SubscriptionPlanPrice;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Plan-price authority (Plan §13.9, §19.3, §47; ADR-011; Phase 20A). Super-Admin only, platform
 * scope. `platform.plan.view` reads price history; `platform.plan_price.manage` creates/schedules/
 * cancels effective-dated prices (MFA + fresh step-up on the route). Platform-owned rows.
 */
final class SubscriptionPlanPricePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('platform.plan.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('platform.plan_price.manage');
    }

    public function cancel(User $user, SubscriptionPlanPrice $price): bool
    {
        return $this->context->can('platform.plan_price.manage');
    }
}
