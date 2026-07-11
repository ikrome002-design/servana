<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * General platform-settings authority (Plan §19.3; Phase 20A). Super-Admin only, platform scope.
 * `platform.settings.view` reads general platform settings; `.update` appends a new effective
 * version of the general settings map (MFA + fresh step-up on the route). Distinct from the
 * billing-config authority ({@see PlatformBillingSettingsPolicy}).
 */
final class PlatformSettingsPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user): bool
    {
        return $this->context->can('platform.settings.view');
    }

    public function update(User $user): bool
    {
        return $this->context->can('platform.settings.update');
    }
}
