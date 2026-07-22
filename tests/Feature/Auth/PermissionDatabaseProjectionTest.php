<?php

declare(strict_types=1);

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'matrix');

/*
 | The DB projection (permissions table) is materialised from the canonical
 | registry by PermissionSeeder and must equal the active runtime set exactly —
 | no orphan of the retired audit.view_full, no planned canonical key.
 */

it('seeds exactly the active registry keys with their mutating flag', function (): void {
    $this->seed(PermissionSeeder::class);
    $registry = app(PermissionRegistry::class);

    $rows = Permission::query()->get()->keyBy('key');

    expect($rows->keys()->sort()->values()->all())
        ->toBe(collect($registry->permissionKeys())->sort()->values()->all());

    foreach ($registry->permissions() as $key => $meta) {
        expect((bool) $rows[$key]->is_mutating)->toBe($meta['mutating'], "is_mutating mismatch for {$key}");
    }
});

it('prunes the retired audit.view_full and never projects a planned key', function (): void {
    $this->seed(PermissionSeeder::class);

    expect(Permission::query()->where('key', 'audit.view_full')->exists())->toBeFalse();
    expect(Permission::query()->where('key', 'audit.flag')->exists())->toBeFalse();
    // A representative planned canonical key must not be projected. (`personnel.my_sms.send`
    // is a still-planned Phase 21S key; `payout_run.mark_paid` was activated by Phase 20H and is
    // now correctly projected, so it is no longer a valid planned-key example.)
    expect(Permission::query()->where('key', 'personnel.my_sms.send')->exists())->toBeFalse();
    expect(Permission::query()->where('key', 'platform.audit.export')->exists())->toBeFalse();
});
