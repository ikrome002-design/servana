<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Subscription-plan catalogue authority (Plan §13.9, §19.3, §47; Phase 20A). Super-Admin only,
 * platform scope. `platform.plan.view` reads; `platform.plan.manage` creates/updates-metadata/
 * retires (and manages entitlements). No merchant/branch ownership — platform-owned rows.
 */
final class SubscriptionPlanPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('platform.plan.view');
    }

    public function view(User $user, SubscriptionPlan $plan): bool
    {
        return $this->context->can('platform.plan.view');
    }

    public function create(User $user): bool
    {
        return $this->context->can('platform.plan.manage');
    }

    public function update(User $user, SubscriptionPlan $plan): bool
    {
        return $this->context->can('platform.plan.manage');
    }

    public function retire(User $user, SubscriptionPlan $plan): bool
    {
        return $this->context->can('platform.plan.manage');
    }

    public function manageEntitlements(User $user, SubscriptionPlan $plan): bool
    {
        return $this->context->can('platform.plan.manage');
    }
}
