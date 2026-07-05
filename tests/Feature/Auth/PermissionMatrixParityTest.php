<?php

declare(strict_types=1);

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionMatrix;
use App\Domain\Auth\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'matrix');

/*
 | §19.5 parity: the ACTIVE runtime set must be identical across the YAML
 | contract, the PHP registry, and the DB projection (zero mismatches). Planned
 | canonical keys never leak into any runtime projection.
 */

it('matches the PHP registry exactly on the active set (YAML active == registry keys)', function (): void {
    $matrix = app(PermissionMatrix::class);
    $registry = app(PermissionRegistry::class);

    $active = collect($matrix->activeKeys())->sort()->values()->all();
    $php = collect($registry->permissionKeys())->sort()->values()->all();

    expect($active)->toBe($php, "active YAML keys drifted from the PHP registry.\n"
        .'only in YAML: '.implode(', ', array_diff($active, $php))."\n"
        .'only in PHP: '.implode(', ', array_diff($php, $active)));
});

it('projects every active key to the DB and never projects a planned key', function (): void {
    $this->seed(PermissionSeeder::class);
    $matrix = app(PermissionMatrix::class);

    $dbKeys = Permission::query()->pluck('key')->sort()->values()->all();
    $active = collect($matrix->activeKeys())->sort()->values()->all();

    expect($dbKeys)->toBe($active, 'DB projection drifted from the active YAML set');

    foreach ($matrix->plannedKeys() as $planned) {
        expect($dbKeys)->not->toContain($planned, "planned key {$planned} must not be projected to the DB");
    }
});
