<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;

/**
 * Per-request tenant context (Plan §8.1).
 *
 * Bound into the container by ResolveTenantContext after auth:sanctum. Holds the
 * resolved merchant + the user's active membership (or marks platform staff). In
 * Phase 6 it carries identity + status only; the permission set (§10.3) and
 * branch ids (§8.2) are populated by Phases 8 and 7 respectively — the
 * accessors exist now as a stable seam and return empty/false until then.
 *
 * A fresh, empty instance means "no tenant resolved" (unauthenticated, or an
 * authenticated user with neither a membership nor platform-staff status).
 */
final class TenantContext
{
    private ?Merchant $merchant = null;

    private ?MerchantUser $merchantUser = null;

    private bool $platformStaff = false;

    public function setMerchant(Merchant $merchant, MerchantUser $merchantUser): void
    {
        $this->merchant = $merchant;
        $this->merchantUser = $merchantUser;
    }

    public function markPlatformStaff(): void
    {
        $this->platformStaff = true;
    }

    public function hasMerchant(): bool
    {
        return $this->merchant !== null;
    }

    public function merchant(): ?Merchant
    {
        return $this->merchant;
    }

    public function merchantUser(): ?MerchantUser
    {
        return $this->merchantUser;
    }

    public function merchantId(): ?int
    {
        return $this->merchant?->id;
    }

    public function role(): ?MerchantUserRole
    {
        return $this->merchantUser?->role;
    }

    public function isPlatformStaff(): bool
    {
        return $this->platformStaff;
    }

    public function isActiveMerchant(): bool
    {
        return $this->merchant !== null && $this->merchant->status->isActive();
    }

    public function isPendingSetup(): bool
    {
        return $this->merchant !== null && $this->merchant->status->isPendingSetup();
    }

    /**
     * Resolved permission keys (Plan §10.3). Empty until the Phase 8 registry
     * exists — present now so policies/guards can call it against a stable seam.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return [];
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
