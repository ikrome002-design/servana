<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Platform billing-settings authority (Plan §13.9, §19.3, §47; Phase 20A). Super-Admin only,
 * platform scope. `platform.billing_settings.view` reads the effective config; `.update` appends a
 * new effective version (MFA + fresh step-up enforced on the route). No merchant/branch ownership —
 * these rows are platform-owned. Defence-in-depth alongside the route `EnsurePermission`.
 */
final class PlatformBillingSettingsPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user): bool
    {
        return $this->context->can('platform.billing_settings.view');
    }

    public function update(User $user): bool
    {
        return $this->context->can('platform.billing_settings.update');
    }
}
