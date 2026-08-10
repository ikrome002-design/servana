<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Platform SMS billing authority (COR-UI08-001 §9; Plan §19.3; Phase UI-08).
 *
 * REUSES the Phase 20A platform billing-settings permissions — `platform.billing_settings.view`
 * reads the pricing series, usage and reconciliation; `.update` schedules or withdraws a rule
 * (MFA + fresh `billing_configuration` step-up enforced on the route). COR-UI08-001 authorizes
 * **no** SMS-specific permission key, and none exists.
 *
 * Platform scope only: these rows carry no merchant or branch ownership. Defence-in-depth
 * alongside the route `EnsurePermission`.
 */
final class PlatformSmsBillingPolicy
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
