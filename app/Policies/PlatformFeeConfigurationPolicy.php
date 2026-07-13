<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Percentage platform-fee configuration authority (Plan §51, §52, §19.3; Phase 20E). Management is
 * Super-Admin ONLY, platform scope, under `platform.platform_fee.configure` (MFA + fresh
 * BillingConfiguration step-up on mutating routes; enforced by route middleware). No merchant role
 * receives this authority; it never validates payments, fabricates ledger rows, or settles liabilities.
 * Defence-in-depth alongside the route `EnsurePermission`.
 */
final class PlatformFeeConfigurationPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('platform.platform_fee.configure');
    }

    public function view(User $user): bool
    {
        return $this->context->can('platform.platform_fee.configure');
    }

    public function manage(User $user): bool
    {
        return $this->context->can('platform.platform_fee.configure');
    }
}
