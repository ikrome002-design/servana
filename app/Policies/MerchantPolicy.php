<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Merchant-level authority (Plan §10.2/§10.3).
 *
 * (The legacy `updateTier` capability was retired in Phase 20B: billing is a subscription
 * lifecycle, not a one-shot tier flag — see the `merchant.subscription.*` keys. The legacy
 * `manageProfile` capability was retired by REM-SCR-002A: it had no caller anywhere, and the
 * canonical merchant-profile authority now lives in {@see MerchantProfilePolicy} against the
 * `merchant_profiles` row the surface actually edits, under the canonical §19.3
 * `merchant.profile.view` / `merchant.profile.update` keys.)
 */
final class MerchantPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    /*
     | Platform merchant governance (Plan §22, §24.1; Phase 20B). Super-Admin platform scope —
     | these govern ALL merchants (no `sameMerchant`; platform staff hold no merchant tenant
     | context). Authorization is the platform grant only; the mutations mutate `merchants.status`
     | and never `merchants.billing_status`. Defence-in-depth alongside the route `EnsurePermission`.
     */

    public function monitorRegistrations(User $user): bool
    {
        return $this->context->can('platform.registration_monitor.view');
    }

    public function viewGovernance(User $user): bool
    {
        return $this->context->can('platform.merchant.view');
    }

    public function suspend(User $user): bool
    {
        return $this->context->can('platform.merchant.suspend');
    }

    public function reactivate(User $user): bool
    {
        return $this->context->can('platform.merchant.reactivate');
    }

    public function deactivate(User $user): bool
    {
        return $this->context->can('platform.merchant.deactivate');
    }
}
