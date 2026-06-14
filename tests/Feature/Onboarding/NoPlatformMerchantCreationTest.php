<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class)->group('onboarding', 'security');

/*
 | Scope §3.1 exclusion: there is NO Super Administrator / platform path to create
 | a merchant or the first Merchant Administrator, and NO KYC/compliance approval
 | route. Merchants exist only via self-registration. These tests assert the route
 | table itself, so the absence is structural (route-list diff is the proof).
 */

/** @return list<string> "METHOD uri" for every registered route. */
function routeSignatures(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn ($route): array => array_map(
            fn (string $method): string => $method.' '.$route->uri(),
            $route->methods(),
        ))
        ->values()
        ->all();
}

it('exposes the self-registration route', function (): void {
    expect(routeSignatures())->toContain('POST api/v1/merchant-registration/self-register');
});

it('has no platform or super-admin merchant-creation route', function (): void {
    $signatures = routeSignatures();

    foreach ($signatures as $signature) {
        // No write route under a platform/super-admin namespace that creates merchants.
        expect($signature)->not->toMatch('#^(POST|PUT|PATCH) api/v1/platform/merchants#');
        expect($signature)->not->toContain('super-admin/merchants');
    }
});

it('has no KYC / compliance / approval route', function (): void {
    foreach (routeSignatures() as $signature) {
        expect($signature)->not->toContain('kyc');
        expect($signature)->not->toContain('compliance');
        expect($signature)->not->toContain('merchant-registration/approve');
        expect($signature)->not->toContain('merchants/activate');
    }
});
