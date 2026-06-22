<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureActivePrincipal;
use App\Http\Middleware\EnsurePrivilegedMfa;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;

uses(RefreshDatabase::class)->group('auth', 'security');

/*
 | Per-request freshness ordering (Plan §79 R6). The active-principal gate runs
 | immediately after authentication and BEFORE the privileged-MFA gate and tenant
 | context resolution — proven structurally (priority-sorted stack) and
 | behaviourally (a revoked principal is denied before MFA/tenant work runs). The
 | R3 MFA ordering (MFA before tenant context) is preserved.
 */

it('places EnsureActivePrincipal after auth and before MFA and tenant context', function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $route = $router->getRoutes()->getByName('me');
    $middleware = $router->gatherRouteMiddleware($route);

    $authIndex = collect($middleware)->search(
        fn (string $m): bool => str_starts_with($m, 'Illuminate\\Auth\\Middleware\\Authenticate'),
    );
    $principalIndex = array_search(EnsureActivePrincipal::class, $middleware, true);
    $mfaIndex = array_search(EnsurePrivilegedMfa::class, $middleware, true);
    $tenantIndex = array_search(ResolveTenantContext::class, $middleware, true);

    expect($authIndex)->not->toBeFalse()
        ->and($principalIndex)->not->toBeFalse()
        ->and($mfaIndex)->not->toBeFalse()
        ->and($tenantIndex)->not->toBeFalse()
        ->and($authIndex)->toBeLessThan($principalIndex)
        ->and($principalIndex)->toBeLessThan($mfaIndex)
        ->and($mfaIndex)->toBeLessThan($tenantIndex);
});

it('denies a suspended principal with 401 before the MFA gate runs', function (): void {
    // A suspended Merchant Admin is a mandatory-MFA role. If the active-principal
    // gate runs first we get 401 unauthenticated; if MFA ran first we would see a
    // 403 mfa_* code. The 401 proves the ordering.
    [$admin] = activeAdmin();
    // status is not mass-assignable (set by lifecycle code) — assign directly.
    $admin->status = User::STATUS_SUSPENDED;
    $admin->save();

    $this->actingAs($admin->fresh(), 'sanctum')
        ->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('lets an active principal through the gate', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/me')
        ->assertStatus(200);
});

it('denies a deactivated platform user before tenant resolution', function (): void {
    $platform = User::factory()->create(['is_platform_staff' => true]);
    $platform->status = User::STATUS_DEACTIVATED;
    $platform->save();

    $this->actingAs($platform->fresh(), 'sanctum')
        ->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});
