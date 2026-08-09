<?php

declare(strict_types=1);

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'phase22', 'search');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 |==============================================================================
 | D-22-01 — the search gate adds NO permission (docs/proof/phase-22.md §3).
 |
 | The live matrix holds no Phase 22 key and no `search.*` key of any kind. Rather
 | than invent `search.global.view` or broaden the front-office-only
 | `front_office.search`, Phase 22 treats search as an AGGREGATOR over existing
 | authorities. These tests are the standing guard on that decision: they fail if a
 | future change quietly introduces a global search permission, broadens the
 | existing one, or churns the matrix for search's sake.
 |==============================================================================
 */

it('introduces no search.* permission key anywhere in the catalogue', function (): void {
    $searchKeys = array_values(array_filter(
        app(PermissionRegistry::class)->permissionKeys(),
        static fn (string $key): bool => str_starts_with($key, 'search.'),
    ));

    expect($searchKeys)->toBe([]);
});

it('introduces no search.global.view key, in the registry, the matrix, the database or TypeScript', function (): void {
    $matrix = Yaml::parseFile(base_path('docs/auth/permission-matrix.yaml'));
    /** @var array<string, mixed> $matrixKeys */
    $matrixKeys = $matrix['keys'] ?? [];

    $registryKeys = app(PermissionRegistry::class)->permissionKeys();
    $generatedTypes = (string) File::get(base_path('resources/spa/src/types/generated/permissions.ts'));

    foreach (['search.global.view', 'platform.search.view', 'merchant.search.view', 'search.view'] as $invented) {
        expect($registryKeys)->not->toContain($invented)
            ->and($matrixKeys)->not->toHaveKey($invented)
            ->and(Permission::query()->where('key', $invented)->exists())->toBeFalse()
            ->and($generatedTypes)->not->toContain($invented);
    }
});

it('leaves front_office.search exactly as it was — front office only, branch scope', function (): void {
    $matrix = Yaml::parseFile(base_path('docs/auth/permission-matrix.yaml'));
    /** @var array<string, mixed> $row */
    $row = $matrix['keys']['front_office.search'];

    expect($row['implementation_status'])->toBe('active')
        ->and($row['scope'])->toBe('branch')
        ->and($row['default_roles'])->toBe(['front_office'])
        ->and($row['billing_read_only_behavior'])->toBe('allow_read');
});

it('grants front_office.search to no role other than front office', function (): void {
    $registry = app(PermissionRegistry::class);
    $offenders = [];

    foreach ($registry->roleKeys() as $role) {
        if ($role === PermissionRegistry::ROLE_FRONT_OFFICE) {
            continue;
        }

        if (in_array('front_office.search', $registry->defaultGrantsFor($role), true)) {
            $offenders[] = $role;
        }
    }

    expect($offenders)->toBe([], 'front_office.search must not be broadened to: '.implode(', ', $offenders));
});

it('keeps the active and planned key counts unchanged by Phase 22', function (): void {
    $matrix = Yaml::parseFile(base_path('docs/auth/permission-matrix.yaml'));
    /** @var array<string, array<string, mixed>> $permissions */
    $permissions = $matrix['keys'];

    $active = count(array_filter(
        $permissions,
        static fn (array $row): bool => ($row['implementation_status'] ?? null) === 'active',
    ));
    $planned = count(array_filter(
        $permissions,
        static fn (array $row): bool => ($row['implementation_status'] ?? null) === 'planned',
    ));

    // Phase 22 activates NOTHING — but the absolute split moves with every LATER phase
    // (21S left 130/38; Phase 23 activated `staff.view` → 131/37 and the merchant-profile pair
    // → 133/35, then retired the legacy duplicate `merchant.profile.manage` → 132/35, catalogue
    // 167), so — following the Phase 20H precedent — assert the invariant the Phase 22 claim
    // actually rests on: no phase INVENTS a canonical key, and no key is owned by Phase 22
    // (proven exhaustively by the `no Phase 22 owned key at all` case below).
    // Phase UI-08 (COR-UI08-001) added EXACTLY TWO keys — `platform.internal_access.view` and
    // `platform.internal_access.manage` — under an explicit product-owner decision, taking the
    // catalogue 167 → 169 (134 active / 35 planned). That is the only growth this assertion has
    // ever permitted, and it is itemised: the invariant Phase 22 rests on is unchanged, because
    // Phase 22 still owns no key at all (proven exhaustively by the case below).
    expect($active + $planned)->toBe(169, 'the catalogue changes only by an explicitly authorized key — never silently');
});

it('leaves the matrix with no Phase 22 owned key at all', function (): void {
    $matrix = Yaml::parseFile(base_path('docs/auth/permission-matrix.yaml'));
    /** @var array<string, array<string, mixed>> $permissions */
    $permissions = $matrix['keys'];

    $phase22 = array_keys(array_filter(
        $permissions,
        static fn (array $row): bool => in_array($row['owning_phase'] ?? null, ['Phase 22', '22'], true),
    ));

    expect($phase22)->toBe([]);
});

it('adds no search row to the phase-8 matrix projection', function (): void {
    $projection = (string) File::get(base_path('docs/proof/phase8-matrix.txt'));

    expect($projection)->not->toContain('search.global')
        ->and(substr_count($projection, 'front_office.search'))->toBe(1);
});
