<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Merchant-level authority (Plan §10.2/§10.3). Profile management is a Merchant
 * Admin capability, scoped to the actor's own merchant. (The legacy `updateTier`
 * capability was retired in Phase 20B: billing is a subscription lifecycle, not a
 * one-shot tier flag — see the `merchant.subscription.*` keys.)
 */
final class MerchantPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function manageProfile(User $user, Merchant $merchant): bool
    {
        return $this->sameMerchant($merchant) && $this->context->can('merchant.profile.manage');
    }

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

    private function sameMerchant(Merchant $merchant): bool
    {
        return $merchant->id === $this->context->merchantId();
    }
}
