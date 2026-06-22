<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Http\Middleware\EnsurePrivilegedMfa;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;

uses(RefreshDatabase::class)->group('auth', 'mfa');

/*
 | Enforcement order (Plan §18, §9.4 step 2): MFA is checked immediately after
 | authentication and BEFORE tenant context. Proven structurally (the resolved,
 | priority-sorted middleware stack) and behaviourally (the gate fires before
 | route-model binding).
 */

it('places EnsurePrivilegedMfa after auth and before ResolveTenantContext', function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $route = $router->getRoutes()->getByName('me');

    $middleware = $router->gatherRouteMiddleware($route);

    $authIndex = collect($middleware)->search(
        fn (string $m): bool => str_starts_with($m, 'Illuminate\\Auth\\Middleware\\Authenticate'),
    );
    $mfaIndex = array_search(EnsurePrivilegedMfa::class, $middleware, true);
    $tenantIndex = array_search(ResolveTenantContext::class, $middleware, true);

    expect($authIndex)->not->toBeFalse()
        ->and($mfaIndex)->not->toBeFalse()
        ->and($tenantIndex)->not->toBeFalse()
        ->and($authIndex)->toBeLessThan($mfaIndex)
        ->and($mfaIndex)->toBeLessThan($tenantIndex);
});

it('checks MFA before tenant route-model binding', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    // The admin owns this branch, so without the MFA gate the bound route would
    // resolve. Instead the MFA gate denies first — proving it runs before
    // tenant context / SubstituteBindings.
    $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'mfa_enrollment_required');
});
