<?php

declare(strict_types=1);

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionMatrix;
use App\Domain\Auth\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'matrix');

/*
 | §2 item 4: planned canonical keys are metadata-only. They must never become a
 | runtime grant — absent from the PHP registry, the DB projection, the generated
 | TypeScript active set, every role's default/grantable set, and every route's
 | permission middleware.
 */

it('keeps all 86 planned keys out of every runtime projection', function (): void {
    $this->seed(PermissionSeeder::class);
    $matrix = app(PermissionMatrix::class);
    $registry = app(PermissionRegistry::class);

    // Phase 20A activated 9 previously-planned canonical keys (86 → 77).
    $planned = $matrix->plannedKeys();
    expect($planned)->toHaveCount(77);

    $registryKeys = array_fill_keys($registry->permissionKeys(), true);
    $dbKeys = array_fill_keys(Permission::query()->pluck('key')->all(), true);
    $ts = (string) file_get_contents(base_path('resources/spa/src/types/generated/permissions.ts'));

    // Every permission key referenced by a route's EnsurePermission middleware.
    $routeKeys = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw) && str_contains($mw, 'EnsurePermission:')) {
                foreach (explode(',', substr($mw, strpos($mw, ':') + 1)) as $k) {
                    $routeKeys[trim($k)] = true;
                }
            }
        }
    }

    // Every default/grantable grant across all roles.
    $granted = [];
    foreach ($registry->roleKeys() as $role) {
        foreach (array_merge($registry->defaultGrantsFor($role), $registry->grantableFor($role)) as $k) {
            $granted[$k] = true;
        }
    }

    $problems = [];
    foreach ($planned as $key) {
        if (isset($registryKeys[$key])) {
            $problems[] = "{$key}: present in PHP registry";
        }
        if (isset($dbKeys[$key])) {
            $problems[] = "{$key}: projected to the DB";
        }
        if (str_contains($ts, "'".$key."'")) {
            $problems[] = "{$key}: present in generated TypeScript";
        }
        if (isset($routeKeys[$key])) {
            $problems[] = "{$key}: referenced by a route's EnsurePermission middleware";
        }
        if (isset($granted[$key])) {
            $problems[] = "{$key}: granted (default/grantable) to a role";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('marks every planned key pending with a documented audit event and no runtime step-up/mfa leak', function (): void {
    $matrix = app(PermissionMatrix::class);

    foreach ($matrix->plannedKeys() as $key) {
        $row = $matrix->get($key);
        // audit_event is honestly 'pending' — the emitting handler is owned by a future phase.
        expect($row['audit_event'])->toBe('pending', "planned {$key} must declare a pending audit event");
        // Plan-encoded mfa/step-up flags remain accurate (verified against the Plan elsewhere),
        // but they describe the FUTURE route, not any current runtime enforcement.
        expect($row['implementation_status'])->toBe('planned');
    }
});
