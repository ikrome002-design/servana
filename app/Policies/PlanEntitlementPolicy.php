<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Plan-entitlement authority (Plan §13.9, §19.3, §20, §47; Phase 20A). Super-Admin only, platform
 * scope. Managed under `platform.plan.manage` (the Plan does NOT define a separate
 * `platform.entitlement.manage` key); `platform.plan.view` reads. Platform-owned rows.
 */
final class PlanEntitlementPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('platform.plan.view');
    }

    public function update(User $user): bool
    {
        return $this->context->can('platform.plan.manage');
    }
}
