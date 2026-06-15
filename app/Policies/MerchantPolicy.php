<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Merchants\Models\Merchant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Merchant-level authority (Plan §10.2/§10.3). Profile + tier are Merchant Admin
 * capabilities; both are scoped to the actor's own merchant.
 */
final class MerchantPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function manageProfile(User $user, Merchant $merchant): bool
    {
        return $this->sameMerchant($merchant) && $this->context->can('merchant.profile.manage');
    }

    public function updateTier(User $user, Merchant $merchant): bool
    {
        return $this->sameMerchant($merchant) && $this->context->can('merchant.tier.update');
    }

    private function sameMerchant(Merchant $merchant): bool
    {
        return $merchant->id === $this->context->merchantId();
    }
}
