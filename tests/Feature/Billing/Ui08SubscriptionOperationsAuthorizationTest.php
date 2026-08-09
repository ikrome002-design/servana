<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('billing', 'ui08', 'ui08-subscription-operations');

/*
 | COR-UI08-001 §10 — authorization boundaries for platform subscription operations.
 |
 | The page is authorized by platform.merchant.view. The two claims worth proving are the ones a
 | reader would otherwise have to take on trust: that the platform key genuinely gates it, and that
 | a MERCHANT-TENANT subscription key grants nothing here no matter how similar its name looks.
 */

/** @return list<string> the seven read routes this domain owns */
function ui08SubscriptionRoutes(): array
{
    return [
        '/api/v1/platform/subscription-operations/summary',
        '/api/v1/platform/subscriptions',
        '/api/v1/platform/subscription-invoices',
        '/api/v1/platform/billing-credits',
        '/api/v1/platform/subscription-escalations',
    ];
}

it('lets a super administrator read every subscription-operations route', function (): void {
    $admin = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($admin);

    foreach (ui08SubscriptionRoutes() as $route) {
        test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
            ->getJson($route)
            ->assertOk();
    }
});

it('denies a merchant user every subscription-operations route', function (): void {
    $scn = invoiceScenario();

    foreach (ui08SubscriptionRoutes() as $route) {
        test()->statefulMfa(now()->getTimestamp())->actingAs($scn['actor'], 'sanctum')
            ->getJson($route)
            ->assertForbidden();
    }
});

it('denies an unauthenticated caller', function (): void {
    test()->getJson('/api/v1/platform/subscriptions')->assertUnauthorized();
});

it('does not enumerate a foreign or unknown subscription or invoice identifier', function (): void {
    $admin = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($admin);

    foreach ([
        '/api/v1/platform/subscriptions/'.Str::ulid(),
        '/api/v1/platform/subscription-invoices/'.Str::ulid(),
    ] as $route) {
        test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
            ->getJson($route)
            ->assertNotFound();
    }
});

it('is authorized by the platform key and never by a merchant-tenant subscription key', function (): void {
    /*
     | The distinction COR-UI08-001 §10.2 insists on. Every route in this domain is gated by
     | EnsurePermission:platform.merchant.view. A merchant key such as merchant.subscription.view
     | appears nowhere in the gate, so holding it cannot open the page — and the merchant-user
     | denial case above demonstrates the runtime consequence.
     */
    $gates = [];

    foreach (RouteFacade::getRoutes() as $route) {
        $name = $route->getName() ?? '';

        if (! str_starts_with($name, 'platform.subscription')
            && ! str_starts_with($name, 'platform.billing-credits')) {
            continue;
        }

        $gates[$name] = array_values(array_filter(
            app('router')->gatherRouteMiddleware($route),
            static fn (string $middleware): bool => str_contains($middleware, 'EnsurePermission'),
        ));
    }

    expect($gates)->not->toBeEmpty();

    foreach ($gates as $name => $middleware) {
        expect($middleware)->toHaveCount(1, $name.' must carry exactly one permission gate');
        expect(str_contains($middleware[0], ':platform.merchant.view'))
            ->toBeTrue($name.' must be gated by platform.merchant.view, found '.$middleware[0]);
        expect(str_contains($middleware[0], 'merchant.subscription'))
            ->toBeFalse($name.' must never be gated by a merchant-tenant subscription key');
    }
});

it('exposes no mutation whatsoever on the subscription-operations surface', function (): void {
    $mutations = [];

    foreach (RouteFacade::getRoutes() as $route) {
        $name = $route->getName() ?? '';

        if (! str_starts_with($name, 'platform.subscription') && ! str_starts_with($name, 'platform.billing-credits')) {
            continue;
        }

        if (array_diff($route->methods(), ['GET', 'HEAD']) !== []) {
            $mutations[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }

    expect($mutations)->toBe([], 'this surface is monitoring only — recovery is a merchant-side payment outcome');
});
