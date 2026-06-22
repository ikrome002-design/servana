<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Routing\RouteClass;
use App\Http\Routing\RouteClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route as RouteFacade;

uses(RefreshDatabase::class)->group('idempotency', 'security');

/*
 | Coverage guard (Plan §24, §24.4; Phase R4). Every registered financial_mutation
 | route MUST carry the idempotency middleware. The guard also provably detects an
 | unprotected financial route (synthetic, in-memory) and passes once corrected.
 | Phase 10 extends this into the full RouteSecurityContractTest.
 */

it('has idempotency middleware on every registered financial_mutation route', function (): void {
    $missing = RouteClassification::financialRoutesMissingIdempotency(RouteFacade::getRoutes());

    expect($missing)->toBe([]);
});

it('detects a financial route that is missing the idempotency middleware', function (): void {
    $unprotected = new IlluminateRoute(['POST'], 'synthetic/unprotected', [
        'uses' => fn () => null,
        'as' => 'synthetic.unprotected',
        'middleware' => ['auth:sanctum'], // deliberately no idempotency
    ]);
    $unprotected->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value);

    $missing = RouteClassification::financialRoutesMissingIdempotency([$unprotected]);

    expect($missing)->toContain('synthetic.unprotected');
});

it('passes once the financial route gains the idempotency middleware', function (): void {
    $protected = new IlluminateRoute(['POST'], 'synthetic/protected', [
        'uses' => fn () => null,
        'as' => 'synthetic.protected',
        'middleware' => [EnsureIdempotentRequest::class.':'.EnsureIdempotentRequest::RETENTION_RETRIABLE],
    ]);
    $protected->defaults(RouteClassification::KEY, RouteClass::FinancialMutation->value);

    expect(RouteClassification::financialRoutesMissingIdempotency([$protected]))->toBe([]);
});
