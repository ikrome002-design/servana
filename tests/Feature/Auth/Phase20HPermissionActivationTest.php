<?php

declare(strict_types=1);

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionMatrix;
use App\Domain\Auth\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'phase20h');

/*
 | Phase 20H Increment 5 — the atomic permission flip (Plan §62/§63, §19.2/§19.3). Sixteen canonical keys
 | are activated (HR payout draft workflow; Finance verify/approve/reject/mark-paid + earnings-query
 | respond; Merchant-Admin compensation-summary + high-value approval; Personnel own-scope earnings/
 | statements/queries). NO legacy key is retired. Parity across YAML/PHP/DB/TypeScript/phase8 is proven
 | exhaustively in the matrix parity/reconciliation/isolation suites; this asserts the specific 20H delta.
 */

const P20H_ACTIVATED = [
    'payout_run.create',
    'payout_run.update_draft',
    'payout_run.submit',
    'payout_run.cancel_draft',
    'payout_run.verify',
    'payout_run.approve_standard',
    'payout_run.reject',
    'payout_run.mark_paid',
    'earnings_query.respond',
    'merchant.compensation_summary.view',
    'merchant.payout.approve_high_value',
    'personnel.my_compensation.view',
    'personnel.my_earnings.view',
    'personnel.my_statements.download',
    'personnel.my_payouts.view',
    'personnel.my_earnings_query.create',
];

it('activates exactly the sixteen canonical Phase 20H keys across YAML + PHP registry + DB', function (): void {
    $this->seed(PermissionSeeder::class);
    $matrix = app(PermissionMatrix::class);
    $registry = array_fill_keys(app(PermissionRegistry::class)->permissionKeys(), true);
    $active = array_fill_keys($matrix->activeKeys(), true);
    $dbKeys = array_fill_keys(Permission::query()->pluck('key')->all(), true);

    foreach (P20H_ACTIVATED as $key) {
        expect($active)->toHaveKey($key, "{$key} must be active in the YAML");
        expect($registry)->toHaveKey($key, "{$key} must be in the PHP registry");
        expect($dbKeys)->toHaveKey($key, "{$key} must be projected to the DB");
        // An active canonical key carries no owning phase / successor (final form).
        expect($matrix->get($key)['implementation_status'])->toBe('active');
        expect($matrix->get($key)['owning_phase'] ?? null)->toBeNull();
    }
});

it('lifts the active count to 128 and drops planned to 40 with no legacy retirement', function (): void {
    $matrix = app(PermissionMatrix::class);

    expect($matrix->activeKeys())->toHaveCount(128);
    expect($matrix->plannedKeys())->toHaveCount(40);
});

it('grants the payout/earnings keys to exactly the right roles and no others', function (): void {
    $registry = app(PermissionRegistry::class);
    $hr = $registry->defaultGrantsFor(PermissionRegistry::ROLE_HR);
    $finance = $registry->defaultGrantsFor(PermissionRegistry::ROLE_FINANCE);
    $admin = $registry->defaultGrantsFor(PermissionRegistry::ROLE_MERCHANT_ADMIN);
    $personnel = $registry->defaultGrantsFor(PermissionRegistry::ROLE_PERSONNEL);

    foreach (['payout_run.create', 'payout_run.update_draft', 'payout_run.submit', 'payout_run.cancel_draft'] as $key) {
        expect($hr)->toContain($key);
        expect($finance)->not->toContain($key);
        expect($admin)->not->toContain($key);
    }
    foreach (['payout_run.verify', 'payout_run.approve_standard', 'payout_run.reject', 'payout_run.mark_paid', 'earnings_query.respond'] as $key) {
        expect($finance)->toContain($key);
        expect($hr)->not->toContain($key);
        expect($admin)->not->toContain($key);
    }
    foreach (['merchant.compensation_summary.view', 'merchant.payout.approve_high_value'] as $key) {
        expect($admin)->toContain($key);
        expect($finance)->not->toContain($key);
        expect($hr)->not->toContain($key);
    }
    foreach (['personnel.my_compensation.view', 'personnel.my_earnings.view', 'personnel.my_statements.download', 'personnel.my_payouts.view', 'personnel.my_earnings_query.create'] as $key) {
        expect($personnel)->toContain($key);
        expect($finance)->not->toContain($key);
        expect($admin)->not->toContain($key);
    }
    // Guardrail: HR is never granted service.view by this phase (Plan §10.2; 20G Inc6A precedent).
    expect($hr)->not->toContain('service.view');
});

it('requires fresh step-up on verify/approve/mark-paid/high-value and not on reads/reject/query-create', function (): void {
    $matrix = app(PermissionMatrix::class);

    foreach (['payout_run.verify', 'payout_run.approve_standard', 'payout_run.mark_paid', 'merchant.payout.approve_high_value'] as $key) {
        expect($matrix->get($key)['step_up_required'])->toBeTrue("{$key} must require fresh step-up");
    }
    foreach (['payout_run.reject', 'earnings_query.respond', 'merchant.compensation_summary.view',
        'personnel.my_earnings.view', 'personnel.my_earnings_query.create', 'payout_run.create'] as $key) {
        expect($matrix->get($key)['step_up_required'])->toBeFalse("{$key} must not require fresh step-up");
    }
});

it('keeps mark-paid + high-value approval at critical severity', function (): void {
    $matrix = app(PermissionMatrix::class);

    expect($matrix->get('payout_run.mark_paid')['audit_severity'])->toBe('crit');
    expect($matrix->get('merchant.payout.approve_high_value')['audit_severity'])->toBe('crit');
});

it('leaves the deferred payout/notification families planned (20D-W / 21N / 21S / 22 / 23 / 24 / 25)', function (): void {
    $matrix = app(PermissionMatrix::class);
    $planned = array_fill_keys($matrix->plannedKeys(), true);

    // Representative deferred keys from later phases remain planned.
    expect($planned)->toHaveKey('personnel.my_sms.send'); // Phase 21S
    expect($planned)->toHaveKey('platform.billing_reconciliation.resolve'); // Phase 20D-W
});
