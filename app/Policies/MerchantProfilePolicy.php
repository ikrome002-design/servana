<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Merchants\Models\MerchantProfile;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Merchant business-profile authority (REM-SCR-002A; Plan §19.3:1444–1445).
 *
 * READ and WRITE are separate keys — `merchant.profile.view` (`M|-|A|…|info`) and
 * `merchant.profile.update` (`M|-|R|…|high`). The read key never implies the write key; this
 * is the same separation Phase 23 established for staff reads (PH23-SEC-001).
 *
 * Tenant scope is resolved from the caller's membership and never accepted from input, so
 * there is no foreign subject to authorize against — but the profile is compared against the
 * resolved merchant anyway, so a stale binding can never widen the subject.
 */
final class MerchantProfilePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user, MerchantProfile $profile): bool
    {
        return $this->ownMerchant($profile) && $this->context->can('merchant.profile.view');
    }

    public function update(User $user, MerchantProfile $profile): bool
    {
        return $this->ownMerchant($profile) && $this->context->can('merchant.profile.update');
    }

    private function ownMerchant(MerchantProfile $profile): bool
    {
        $merchantId = $this->context->merchantId();

        return $merchantId !== null && $profile->merchant_id === $merchantId;
    }
}
