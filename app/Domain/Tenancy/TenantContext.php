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

    /** @var list<int> Active branch ids for a branch-scoped membership (Plan §8.2). */
    private array $branchIds = [];

    /** @var list<string> Resolved permission keys for this request (Plan §10.3). */
    private array $permissions = [];

    /** True when a tenant-aware job bound a branch-scoped context (no membership). */
    private bool $jobBranchScoped = false;

    /**
     * Clear all resolved state. ResolveTenantContext calls this before each
     * (re)resolution so a `scoped` instance reused across requests (e.g. the
     * test client, or any double-resolve) never leaks a stale merchant.
     */
    public function reset(): void
    {
        $this->merchant = null;
        $this->merchantUser = null;
        $this->platformStaff = false;
        $this->branchIds = [];
        $this->permissions = [];
        $this->jobBranchScoped = false;
    }

    public function setMerchant(Merchant $merchant, MerchantUser $merchantUser): void
    {
        $this->merchant = $merchant;
        $this->merchantUser = $merchantUser;
        $this->branchIds = $merchantUser->isBranchScoped() ? $merchantUser->activeBranchIds() : [];
    }

    /**
     * Bind a merchant context for a tenant-aware background job (Plan §8.3).
     * There is no membership inside a job; an optional branch id narrows the
     * branch scope. Permissions stay empty — jobs never run permission checks.
     */
    public function bindForJob(Merchant $merchant, ?int $branchId = null): void
    {
        $this->reset();
        $this->merchant = $merchant;

        if ($branchId !== null) {
            $this->branchIds = [$branchId];
            $this->jobBranchScoped = true;
        }
    }

    public function markPlatformStaff(): void
    {
        $this->platformStaff = true;
    }

    /**
     * Set the request-cached permission set (Plan §10.3). Called once per request
     * by ResolveTenantContext after the membership/platform-staff is resolved.
     *
     * @param  list<string>  $permissions
     */
    public function setPermissions(array $permissions): void
    {
        $this->permissions = $permissions;
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
     * Whether the current membership is branch-scoped (everything except
     * merchant_admin). A merchant_admin sees all own-merchant branches.
     */
    public function isBranchScoped(): bool
    {
        if ($this->jobBranchScoped) {
            return true;
        }

        return $this->merchantUser !== null && $this->merchantUser->isBranchScoped();
    }

    /**
     * Active branch ids for a branch-scoped membership (Plan §8.2). Empty for a
     * merchant_admin (meaning "all own-merchant branches", not "none").
     *
     * @return list<int>
     */
    public function branchIds(): array
    {
        return $this->branchIds;
    }

    /** Whether this context may access the given branch id within its merchant. */
    public function canAccessBranch(int $branchId): bool
    {
        if (! $this->isBranchScoped()) {
            return true; // merchant_admin: all own-merchant branches
        }

        return in_array($branchId, $this->branchIds, true);
    }

    /**
     * Resolved permission keys for this request (Plan §10.3). Populated by
     * ResolveTenantContext via PermissionResolver (role default grants ± per-user
     * overrides; empty for a suspended/deactivated or unresolved membership).
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
