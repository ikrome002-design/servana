<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class)->group('search', 'phase22', 'security');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 |==============================================================================
 | Rate limiting is the ENUMERATION control (Plan §68, §9.3; §73 RK-05).
 |
 | Search is the cheapest surface on which to probe for the existence of a name or
 | a number, so the pre-existing `search` limiter (60/min per authenticated
 | principal, defined since Phase 10 and unused until now) is wired to it.
 |==============================================================================
 */

it('carries the search limiter in addition to the group api limiter', function (): void {
    $route = Route::getRoutes()->getByName('search.index');

    expect($route)->not->toBeNull();

    $middleware = Route::gatherRouteMiddleware($route);
    $throttles = array_values(array_filter(
        $middleware,
        static fn (mixed $entry): bool => is_string($entry) && str_contains($entry, 'ThrottleRequests'),
    ));

    // Both limiters apply, so the stricter one (search) governs.
    expect($throttles)->toHaveCount(2)
        ->and(implode(' ', $throttles))->toContain(':search')
        ->and(implode(' ', $throttles))->toContain(':api');
});

it('returns 429 once the per-minute search allowance is exhausted', function (): void {
    $scn = searchScenario();

    $limit = 60;
    $last = null;

    for ($i = 0; $i <= $limit; $i++) {
        $last = search($scn['frontOffice'], ['q' => 'Amina']);

        if ($last->getStatusCode() === 429) {
            break;
        }
    }

    expect($last)->not->toBeNull()
        ->and($last->getStatusCode())->toBe(429);
});

it('serves the structured error envelope when rate limited', function (): void {
    $scn = searchScenario();

    for ($i = 0; $i <= 60; $i++) {
        $response = search($scn['frontOffice'], ['q' => 'Amina']);

        if ($response->getStatusCode() === 429) {
            $response->assertJsonStructure(['error' => ['code', 'message']]);

            return;
        }
    }

    $this->fail('The search limiter never engaged.');
});

it('limits per principal, so one member exhausting the allowance does not lock out another', function (): void {
    $scn = searchScenario();
    [$second] = branchStaff($scn['merchant'], $scn['branchA'], MerchantUserRole::FrontOffice);

    for ($i = 0; $i <= 60; $i++) {
        if (search($scn['frontOffice'], ['q' => 'Amina'])->getStatusCode() === 429) {
            break;
        }
    }

    // The second member still has their own allowance.
    search($second, ['q' => 'Amina'])->assertOk();
});
