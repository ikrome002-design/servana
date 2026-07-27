<?php

declare(strict_types=1);

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionMatrix;
use App\Domain\Auth\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'phase23');

/*
 | Phase 23 §14.1 — the atomic permission flip (Plan §19.2/§19.3; product-owner decision).
 | ONE canonical key is activated, as a SECURITY REMEDIATION rather than a feature:
 |
 |   staff.view — the HR staff roster/detail READ. It was left `implementation_status: planned`
 |     with `owning_phase: Phase 20F` after Phase 20F completed, so `GET /api/v1/staff` shipped
 |     with NO EnsurePermission middleware AND no controller authorize() call, while
 |     StaffProfileResource returns an unmasked `phone`. Every authenticated merchant member —
 |     Front Office, Personnel, and the read-only Audit role included — could enumerate the
 |     branch roster with personnel phone numbers (Plan §9.1 personnel-contact extraction; RK-05).
 |
 | Plan §19.3 grants the key to HR ONLY ("# HR and Staff (default_roles: hr)"). The Branch
 | Manager's shipped read-only personnel-schedule picker is served instead by the narrow
 | `branch.personnel-options.index` endpoint under the `branch.dashboard.view` it already holds,
 | so NO role grant was widened and NO new permission key was invented.
 */

const P23_ACTIVATED = ['staff.view'];

it('activates exactly the one canonical Phase 23 key across YAML + PHP registry + DB + TS', function (): void {
    $this->seed(PermissionSeeder::class);
    $matrix = app(PermissionMatrix::class);
    $registry = array_fill_keys(app(PermissionRegistry::class)->permissionKeys(), true);
    $active = array_fill_keys($matrix->activeKeys(), true);
    $dbKeys = array_fill_keys(Permission::query()->pluck('key')->all(), true);
    $ts = (string) file_get_contents(base_path('resources/spa/src/types/generated/permissions.ts'));

    foreach (P23_ACTIVATED as $key) {
        expect($active)->toHaveKey($key, "{$key} must be active in the YAML");
        expect($registry)->toHaveKey($key, "{$key} must be in the PHP registry");
        expect($dbKeys)->toHaveKey($key, "{$key} must be projected to the DB");
        // `toContain` treats extra arguments as further expected VALUES, not as a message, so the
        // assertion is written as a boolean with the message on `toBeTrue`.
        expect(str_contains($ts, "'{$key}'"))
            ->toBeTrue("{$key} must appear in the generated TypeScript contract");
        // An active canonical key carries no owning phase / successor (final form).
        expect($matrix->get($key)['implementation_status'])->toBe('active');
        expect($matrix->get($key)['owning_phase'] ?? null)->toBeNull();
        expect($matrix->get($key)['canonical_successor'] ?? null)->toBeNull();
    }
});

it('lifts the active count to 132 and drops planned to 35, shrinking the catalogue by one retirement', function (): void {
    $matrix = app(PermissionMatrix::class);

    // Phase 21S left 130 active / 38 planned (catalogue 168); Phase 22 activated nothing.
    // Phase 23 activates THREE canonical keys and RETIRES one legacy key:
    //   +staff.view                          (PH23-SEC-001)   → 131 active / 37 planned / 168
    //   +merchant.profile.view/.update        (REM-SCR-002A)   → 133 active / 35 planned / 168
    //   −merchant.profile.manage  (legacy, retired outright)   → 132 active / 35 planned / 167
    // The catalogue shrinks only because a legacy DUPLICATE was removed — no canonical key was
    // invented or deleted. This is the same retirement precedent Phases 20A/20B/20E/20F applied.
    expect($matrix->activeKeys())->toHaveCount(132);
    expect($matrix->plannedKeys())->toHaveCount(35);
    expect(count($matrix->activeKeys()) + count($matrix->plannedKeys()))
        ->toBe(167, 'the catalogue shrank by exactly the one retired legacy duplicate');

    // The retirement is complete: the legacy name exists nowhere in the runtime projection.
    expect($matrix->keys())->not->toContain('merchant.profile.manage');
    expect(app(PermissionRegistry::class)->permissionKeys())
        ->not->toContain('merchant.profile.manage');
});

it('grants staff.view to HR and to no other role, by default or by override', function (): void {
    $registry = app(PermissionRegistry::class);

    foreach ($registry->roleKeys() as $role) {
        $grants = array_merge($registry->defaultGrantsFor($role), $registry->grantableFor($role));
        $holds = in_array('staff.view', $grants, true);

        if ($role === PermissionRegistry::ROLE_HR) {
            expect($holds)->toBeTrue('hr must hold staff.view');

            continue;
        }

        expect($holds)->toBeFalse("{$role} must never hold staff.view");
    }
});

it('keeps staff.view a pure branch-scoped READ that never implies a staff mutation', function (): void {
    $matrix = app(PermissionMatrix::class);
    $row = $matrix->get('staff.view');

    // Plan §19.3:1481 — `staff.view  B|-|A|n/a|-|-|info|-`, group default_roles: hr.
    expect($row['scope'])->toBe('branch')
        ->and($row['billing_read_only_behavior'])->toBe('allow_read')
        ->and($row['period_lock_behavior'])->toBe('n/a')
        ->and($row['entitlement_key'])->toBeNull()
        ->and($row['mfa_required'])->toBeFalse()
        ->and($row['step_up_required'])->toBeFalse()
        ->and($row['audit_severity'])->toBe('info')
        ->and($row['maker_checker_incompatibilities'])->toBe([])
        ->and($row['override_policy'])->toBe('revocable_only')
        ->and($row['default_roles'])->toBe(['hr']);

    // A pure read is not a mutating permission in the DB projection.
    expect(app(PermissionRegistry::class)->permissions()['staff.view']['mutating'])->toBeFalse();

    // The staff MUTATION keys are untouched — a read key never became a management key.
    $registry = app(PermissionRegistry::class);
    $hr = $registry->defaultGrantsFor(PermissionRegistry::ROLE_HR);
    expect($hr)->toContain('staff.suspend')->toContain('staff.invite')->toContain('staff.edit');
});

it('creates no new permission key and widens no role grant for the Branch Manager', function (): void {
    $registry = app(PermissionRegistry::class);

    // The narrow branch personnel-options endpoint reuses `branch.dashboard.view`; it introduced
    // no `personnel.options.*`, no `staff.options.*`, and no second roster read key.
    foreach ($registry->permissionKeys() as $key) {
        expect(str_contains($key, 'personnel_option') || str_contains($key, 'personnel.options'))
            ->toBeFalse("{$key} would be an invented Phase 23 options permission");
    }

    $branchManager = array_merge(
        $registry->defaultGrantsFor(PermissionRegistry::ROLE_BRANCH_MANAGER),
        $registry->grantableFor(PermissionRegistry::ROLE_BRANCH_MANAGER),
    );

    expect($branchManager)->toContain('branch.dashboard.view')
        ->and($branchManager)->not->toContain('staff.view');
});
