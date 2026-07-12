<?php

declare(strict_types=1);

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionMatrix;
use App\Domain\Auth\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'phase20b');

/*
 | Phase 20B Increment 5 — the atomic permission flip (Plan §22, §24.1, §48, §49). Nine canonical
 | §19.2 keys are activated (four merchant subscription self-service; five platform merchant
 | governance); two dead legacy keys are retired (merchant.tier.update; platform.merchants.govern —
 | truthfully SPLIT, not 1:1 renamed). Parity across YAML/PHP/DB is proven exhaustively in the
 | matrix parity/reconciliation/isolation suites; this asserts the specific Phase-20B delta.
 */

const P20B_ACTIVATED = [
    'merchant.subscription.view',
    'merchant.subscription.plan_change',
    'merchant.subscription.invoice.view',
    'merchant.subscription.invoice.download',
    'platform.registration_monitor.view',
    'platform.merchant.view',
    'platform.merchant.suspend',
    'platform.merchant.reactivate',
    'platform.merchant.deactivate',
];

const P20B_RETIRED = ['merchant.tier.update', 'platform.merchants.govern'];

it('activates exactly the nine canonical Phase 20B keys across YAML + PHP registry', function (): void {
    $matrix = app(PermissionMatrix::class);
    $registry = array_fill_keys(app(PermissionRegistry::class)->permissionKeys(), true);
    $active = array_fill_keys($matrix->activeKeys(), true);

    foreach (P20B_ACTIVATED as $key) {
        expect($active)->toHaveKey($key, "{$key} must be active in the YAML");
        expect($registry)->toHaveKey($key, "{$key} must be in the PHP registry");
        // An active canonical key carries no owning phase / successor (final form).
        expect($matrix->get($key)['implementation_status'])->toBe('active');
        expect($matrix->get($key)['owning_phase'] ?? null)->toBeNull();
    }
});

it('retires the two dead legacy keys everywhere (YAML, registry, DB) and does not claim a false 1:1 successor', function (): void {
    $this->seed(PermissionSeeder::class);
    $matrix = app(PermissionMatrix::class);
    $registryKeys = array_fill_keys(app(PermissionRegistry::class)->permissionKeys(), true);
    $dbKeys = array_fill_keys(Permission::query()->pluck('key')->all(), true);
    $yamlKeys = array_fill_keys($matrix->keys(), true);

    foreach (P20B_RETIRED as $key) {
        expect($registryKeys)->not->toHaveKey($key);
        expect($dbKeys)->not->toHaveKey($key);
        expect($yamlKeys)->not->toHaveKey($key); // removed entirely (no `retired` status exists)
    }
});

it('grants the four merchant keys to merchant_admin and the five platform keys to super_admin', function (): void {
    $registry = app(PermissionRegistry::class);
    $adminGrants = $registry->defaultGrantsFor(PermissionRegistry::ROLE_MERCHANT_ADMIN);
    $superGrants = $registry->defaultGrantsFor(PermissionRegistry::ROLE_SUPER_ADMIN);

    foreach (array_slice(P20B_ACTIVATED, 0, 4) as $key) {
        expect($adminGrants)->toContain($key);
        expect($superGrants)->not->toContain($key);
    }
    foreach (array_slice(P20B_ACTIVATED, 4) as $key) {
        expect($superGrants)->toContain($key);
        expect($adminGrants)->not->toContain($key);
    }
});

it('requires fresh step-up only on the three platform governance mutations, not on reads or plan changes', function (): void {
    $matrix = app(PermissionMatrix::class);

    foreach (['platform.merchant.suspend', 'platform.merchant.reactivate', 'platform.merchant.deactivate'] as $key) {
        expect($matrix->get($key)['step_up_required'])->toBeTrue();
    }
    foreach (['merchant.subscription.view', 'merchant.subscription.plan_change', 'platform.registration_monitor.view', 'platform.merchant.view'] as $key) {
        expect($matrix->get($key)['step_up_required'])->toBeFalse();
    }
});
