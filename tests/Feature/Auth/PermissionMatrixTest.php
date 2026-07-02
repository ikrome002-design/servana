<?php

declare(strict_types=1);

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Models\Role;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions');

/*
 | The permission matrix, transcribed INDEPENDENTLY from the Plan (not from
 | PermissionRegistry) so this test is a genuine spec check: the seeded
 | role_permission_assignments must equal these cells exactly, with zero
 | mismatches. Interpretation per the Plan: any non-empty, non-`◐` cell is a
 | default grant; `◐` cells are grantable overrides (NOT default grants and so
 | absent here); personnel exports are `**never**` (no export key at all).
 |
 | Phase 15A reconciled the catalogue/eligibility/client cells to the CANONICAL
 | §19.2/§19.3 keys (its owning-phase contribution per §19.1): Branch Manager
 | `service.view/create/update/archive` (was `services.manage`); HR
 | `personnel.eligibility.manage` (was `eligibility.manage`); Front Office
 | `client.view/create/update` + `front_office.search` (was `clients.*`). Per
 | §19.3 `client.view` defaults to Front Office only, so the unwired legacy
 | `clients.view` grants on the other roles were dropped in the reconciliation
 | (full §10.3→§19 closure remains Phase 19 / REM-PERM-001).
 |
 | Phase 16B reconciled the QUEUE cells to the canonical §19/§37 keys: the legacy
 | Branch Manager `queue.operate`/`queue.transfer_entries`/`queue.configure` grants
 | were REMOVED (Branch Manager configures the queue via `branch.profile.manage` +
 | `day.open_close` and reads via `branch.dashboard.view` — no operational queue
 | key). Front Office gained `queue.view/create/assign/transfer/reorder` +
 | `preferred_personnel.select` (replacing the legacy `queue.operate`); Personnel
 | gained own-scope `personnel.my_queue.view`. REM-PERM-001 stays open (Phase 19).
 */
function expectedMatrix(): array
{
    return [
        'merchant_admin' => [
            'merchant.profile.manage', 'merchant.tier.update',
            'branches.create', 'branches.manage_users_lifecycle',
            // Phase 17: no invoice key (Plan §10.2/§19.3) — invoice visibility via reports.
            'receipts.view',
            'periods.lock', 'commissions.view', 'platform_fees.view',
            'reports.view', 'audit.view_full',
        ],
        'branch_manager' => [
            'branch.profile.manage', 'branch.calendar.manage', 'branch.dashboard.view',
            'service.view', 'service.create', 'service.update', 'service.archive',
            'day.open_close', 'cashup.submit',
            // Phase 17: NO invoice key — Branch Manager must not create invoices
            // (Plan §10.2/§19.3); legacy invoices.create/view grants removed.
            'receipts.view', 'commissions.view', 'platform_fees.view',
            'reports.view', 'audit.view_full',
        ],
        'hr' => [
            'staff.invite', 'staff.edit', 'staff.suspend',
            'personnel.eligibility.manage', 'personnel.availability.manage', 'commissions.manage',
            'commissions.view', 'reports.view', 'audit.view_full',
            'exports.staff_roster',
        ],
        'finance' => [
            'invoice.view', 'invoice.void.request_or_execute_as_policy', 'invoice.adjustment.manage',
            'customer_payment.view', 'customer_payment.duplicate_override', 'customer_payment.record_exception',
            'receipts.view', 'refunds.request', 'disputes.manage',
            'cashup.review_approve', 'platform_fees.dispute',
            'reports.view', 'audit.view_full',
        ],
        'front_office' => [
            'queue.view', 'queue.create', 'queue.assign', 'queue.transfer', 'queue.reorder',
            'preferred_personnel.select',
            'appointment.view', 'appointment.create', 'appointment.reschedule',
            'appointment.cancel', 'appointment.check_in', 'appointment.assign',
            'appointment.transfer',
            'client.view', 'client.create', 'client.update', 'front_office.search',
            'service_session.view', 'service_session.start',
            'service_session.complete', 'service_session.cancel',
            'invoice.view', 'invoice.create',
            'customer_payment.record', 'receipts.view', 'reports.view',
        ],
        'personnel' => [
            'personnel.my_appointments.view', 'personnel.my_queue.view',
            'personnel.my_sessions.view',
            // Phase 17: no invoice key (strict own-scope; no broad browsing).
            'receipts.view',
            'commissions.view', 'reports.view',
        ],
        'audit' => [
            // Phase 17: no invoice key (Audit reads via audit.view_full/reports).
            'receipts.view',
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
