<?php

declare(strict_types=1);

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Models\Role;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions');

/*
 | The §10.3 permission matrix, transcribed INDEPENDENTLY from the Plan (not from
 | PermissionRegistry) so this test is a genuine spec check: the seeded
 | role_permission_assignments must equal these cells exactly, with zero
 | mismatches. Interpretation per the Plan: any non-empty, non-`◐` cell is a
 | default grant; `◐` cells are grantable overrides (NOT default grants and so
 | absent here); personnel exports are `**never**` (no export key at all).
 */
function expectedMatrix(): array
{
    return [
        'merchant_admin' => [
            'merchant.profile.manage', 'merchant.tier.update',
            'branches.create', 'branches.manage_users_lifecycle',
            'invoices.view', 'invoices.void_paid', 'receipts.view',
            'periods.lock', 'commissions.view', 'platform_fees.view',
            'reports.view', 'audit.view_full',
        ],
        'branch_manager' => [
            'branch.profile.manage', 'branch.calendar.manage', 'services.manage',
            'queue.configure', 'queue.operate', 'queue.transfer_entries',
            'appointments.manage', 'day.open_close', 'cashup.submit',
            'clients.view', 'sessions.manage', 'invoices.create', 'invoices.view',
            'receipts.view', 'commissions.view', 'platform_fees.view',
            'reports.view', 'audit.view_full',
        ],
        'hr' => [
            'staff.invite', 'staff.edit', 'staff.suspend',
            'eligibility.manage', 'availability.manage', 'commissions.manage',
            'commissions.view', 'reports.view', 'audit.view_full',
            'exports.staff_roster',
        ],
        'finance' => [
            'clients.view', 'invoices.view', 'invoices.void_unpaid',
            'payments.record', 'payments.validate', 'payments.reject',
            'receipts.view', 'refunds.request', 'disputes.manage',
            'cashup.review_approve', 'platform_fees.dispute',
            'reports.view', 'audit.view_full',
        ],
        'front_office' => [
            'queue.operate', 'appointments.manage',
            'clients.create', 'clients.edit', 'clients.view',
            'sessions.manage', 'invoices.create', 'invoices.view',
            'payments.record', 'receipts.view', 'reports.view',
        ],
        'personnel' => [
            'clients.view', 'invoices.view', 'receipts.view',
            'commissions.view', 'reports.view',
        ],
        'audit' => [
            'clients.view', 'invoices.view', 'receipts.view',
            'commissions.view', 'platform_fees.view', 'reports.view',
            'audit.view_full', 'audit.flag',
        ],
        'super_admin' => [
            'platform.settings.manage', 'platform.merchants.govern',
            'platform.billing.configure', 'platform.fee_rules.manage',
            'platform.audit.view',
        ],
    ];
}

it('seeds the §10.3 matrix with zero mismatches', function (): void {
    $this->seed(PermissionSeeder::class);

    $allKeys = Permission::query()->pluck('key')->sort()->values()->all();
    $mismatches = [];

    foreach (expectedMatrix() as $roleKey => $expectedKeys) {
        /** @var Role $role */
        $role = Role::query()->where('key', $roleKey)->firstOrFail();
        $seeded = $role->permissions()->pluck('key')->all();

        // Iterate EVERY (role, permission) cell — both grants and non-grants.
        foreach ($allKeys as $key) {
            $shouldGrant = in_array($key, $expectedKeys, true);
            $isGranted = in_array($key, $seeded, true);

            if ($shouldGrant !== $isGranted) {
                $mismatches[] = sprintf('%s × %s expected=%s seeded=%s', $roleKey, $key, $shouldGrant ? 'grant' : 'none', $isGranted ? 'grant' : 'none');
            }
        }
    }

    expect($mismatches)->toBe([], implode("\n", $mismatches));
});

it('matches the canonical PermissionRegistry (DB == registry)', function (): void {
    $this->seed(PermissionSeeder::class);
    $registry = app(PermissionRegistry::class);

    foreach ($registry->roleKeys() as $roleKey) {
        /** @var Role $role */
        $role = Role::query()->where('key', $roleKey)->firstOrFail();
        $seeded = $role->permissions()->pluck('key')->sort()->values()->all();
        $expected = collect($registry->defaultGrantsFor($roleKey))->sort()->values()->all();

        expect($seeded)->toBe($expected, "role {$roleKey} default grants drifted from the registry");
    }
});

it('writes the matrix proof artifact', function (): void {
    $this->seed(PermissionSeeder::class);

    $roles = Role::query()->orderBy('id')->pluck('key')->all();
    $permissions = Permission::query()->orderBy('category')->orderBy('key')->get();

    $lines = ['Servana — Phase 8 permission matrix (seeded §10.3). ✓ = default grant.', ''];
    $header = str_pad('permission', 38).implode('  ', array_map(static fn ($r): string => substr($r, 0, 12), $roles));
    $lines[] = $header;
    $lines[] = str_repeat('-', strlen($header));

    foreach ($permissions as $permission) {
        $row = str_pad($permission->key, 38);
        foreach ($roles as $roleKey) {
            /** @var Role $role */
            $role = Role::query()->where('key', $roleKey)->firstOrFail();
            $granted = $role->permissions()->where('key', $permission->key)->exists();
            $row .= str_pad($granted ? '✓' : '·', 14);
        }
        $lines[] = rtrim($row);
    }

    $path = base_path('docs/proof/phase8-matrix.txt');
    file_put_contents($path, implode("\n", $lines)."\n");

    expect(file_exists($path))->toBeTrue();
})->group('proof');
