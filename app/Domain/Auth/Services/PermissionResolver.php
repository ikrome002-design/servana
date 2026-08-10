<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use App\Domain\Auth\Enums\PermissionOverrideEffect;
use App\Domain\Auth\Models\MerchantUserPermissionOverride;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\PlatformAccess\Enums\PlatformAccessStatus;
use App\Domain\PlatformAccess\Models\PlatformAccessPermissionOverride;

/**
 * Resolves the effective permission set for a membership (Plan §10.3).
 *
 * Resolution = role default grants ∪ per-membership grant overrides − deny
 * overrides, with these invariants:
 *   - DENY ALWAYS beats GRANT.
 *   - A grant override only takes effect for a key the role may be granted (`◐`).
 *   - A suspended/deactivated membership resolves to the EMPTY set (no access).
 *   - The read-only `audit` role can never gain a mutating merchant capability
 *     via override (its in-domain `audit.flag` default is preserved).
 *
 * Default grants are read from the canonical PermissionRegistry (the same source
 * the seeder materialises into role_permission_assignments — PermissionMatrixTest
 * proves DB == registry), so resolution is correct even before seeding. Overrides
 * are per-membership database rows. Results are cached per request by TenantContext.
 */
final class PermissionResolver
{
    public function __construct(private readonly PermissionRegistry $registry) {}

    /**
     * Effective permission keys for a membership.
     *
     * @return list<string>
     */
    public function forMembership(MerchantUser $membership): array
    {
        // Suspended/deactivated/invited memberships hold no permissions.
        if (! $membership->isActive()) {
            return [];
        }

        $roleKey = $membership->role->value;
        $resolved = $this->registry->defaultGrantsFor($roleKey);

        $resolved = $this->applyOverrides($membership, $roleKey, $resolved);

        if ($this->registry->isReadOnlyRole($roleKey)) {
            $resolved = $this->stripMutatingNonDefaults($roleKey, $resolved);
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Effective permission keys for platform staff (the super_admin role).
     *
     * @return list<string>
     */
    public function forPlatformStaff(?int $userId = null): array
    {
        $resolved = array_values(array_unique(
            $this->registry->defaultGrantsFor(PermissionRegistry::ROLE_SUPER_ADMIN)
        ));

        if ($userId === null) {
            return $resolved;
        }

        /*
         | COR-UI08-001 (Phase UI-08): subtract this administrator's DENY overrides.
         |
         | Deny-only by construction — `platform_access_permission_overrides.effect` is
         | CHECK-constrained to 'deny' and a trigger rejects any non-platform permission — so this
         | can only ever REMOVE a key. There is deliberately no grant branch to mirror the
         | membership resolver's: adding one would make self-escalation representable.
         */
        $denied = PlatformAccessPermissionOverride::query()
            ->whereHas(
                'membership',
                static fn ($query) => $query->where('user_id', $userId)
                    ->where('status', PlatformAccessStatus::Active->value),
            )
            ->with('permission')
            ->get()
            ->map(static fn (PlatformAccessPermissionOverride $override): ?string => $override->permission?->key)
            ->filter()
            ->all();

        if ($denied === []) {
            return $resolved;
        }

        return array_values(array_diff($resolved, $denied));
    }

    /**
     * Layer the membership's grant/deny overrides onto the default set.
     *
     * @param  list<string>  $defaults
     * @return list<string>
     */
    private function applyOverrides(MerchantUser $membership, string $roleKey, array $defaults): array
    {
        $overrides = MerchantUserPermissionOverride::query()
            ->where('merchant_user_id', $membership->id)
            ->with('permission')
            ->get();

        if ($overrides->isEmpty()) {
            return $defaults;
        }

        $set = array_fill_keys($defaults, true);

        // Apply grants first, then denies — deny must win on any conflict.
        foreach ($overrides as $override) {
            if ($override->effect !== PermissionOverrideEffect::Grant) {
                continue;
            }
            $key = $override->permission?->key;
            if ($key !== null && $this->registry->isGrantableFor($roleKey, $key)) {
                $set[$key] = true;
            }
        }

        foreach ($overrides as $override) {
            if ($override->effect !== PermissionOverrideEffect::Deny) {
                continue;
            }
            $key = $override->permission?->key;
            if ($key !== null) {
                unset($set[$key]);
            }
        }

        return array_keys($set);
    }

    /**
     * For a read-only role, keep only default keys + any non-mutating key; this
     * guarantees the role can never hold a mutating merchant capability even if
     * an override row was crafted directly.
     *
     * @param  list<string>  $resolved
     * @return list<string>
     */
    private function stripMutatingNonDefaults(string $roleKey, array $resolved): array
    {
        $defaults = array_fill_keys($this->registry->defaultGrantsFor($roleKey), true);

        return array_values(array_filter(
            $resolved,
            fn (string $key): bool => isset($defaults[$key]) || ! $this->registry->isMutating($key),
        ));
    }
}
