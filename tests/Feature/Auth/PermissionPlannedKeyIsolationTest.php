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

it('keeps all 35 planned keys out of every runtime projection', function (): void {
    $this->seed(PermissionSeeder::class);
    $matrix = app(PermissionMatrix::class);
    $registry = app(PermissionRegistry::class);

    // Phase 20A activated 9 previously-planned canonical keys (86 → 77); Phase 20B activated the
    // 9 subscription-lifecycle / merchant-governance canonical keys (77 → 68); Phase 20C activated the
    // 2 promotion / free-period-offer canonical keys (68 → 66); Phase 20F activated the 8 HR
    // compensation-configuration canonical keys (66 → 58); Phase 20G activated the 2 Finance
    // compensation-financial canonical keys compensation.liability.view + compensation.adjustment.create
    // (58 → 56); Phase 20H activated the 16 payout-run / earnings / merchant-compensation-summary
    // canonical keys (56 → 40); Phase 21S activated the 2 Personnel SMS canonical keys
    // personnel.my_served_clients.view + personnel.my_sms.send (40 → 38); Phase 22 activated NOTHING
    // (38); Phase 23 activated the canonical read key staff.view as a security remediation —
    // it was left `planned` with owning_phase "Phase 20F" after 20F completed, leaving
    // GET /api/v1/staff with no authorization boundary at all (38 → 37) — and then activated
    // merchant.profile.view + merchant.profile.update for REM-SCR-002A, the omitted Plan §27.3
    // Merchant Administrator merchant-profile launch screen, retiring the legacy duplicate
    // merchant.profile.manage outright (37 → 35). The remaining planned families belong to
    // Phase 20D-W / 21N / 21R-B / 24 / 25.
    $planned = $matrix->plannedKeys();
    expect($planned)->toHaveCount(35);

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
