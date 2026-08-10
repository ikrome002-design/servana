<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\EnsureMerchantActive;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePrivilegedMfa;
use App\Http\Middleware\RequireFreshMfa;
use App\Http\Middleware\ResolvePlatformContext;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Routing\RouteClass;
use App\Http\Routing\RouteClassification;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

uses()->group('security', 'route-contract', 'ui08');

/*
 |==============================================================================
 | Phase UI-08 — COR-UI08-001 route-security parity, in BOTH directions.
 |
 | The contract matrix is the specification:
 |
 |   docs/backend/audits/ui-08/cor-ui08-001-contract-matrix.json
 |
 | Written in Increment 2b, before any controller existed. Each operation carries an
 | `implementation_state`:
 |
 |   planned      -> the route MUST NOT exist yet. This is a negative control: it catches a
 |                   route that landed without its matrix entry, its permission, its step-up
 |                   or its audit event being specified first.
 |   implemented  -> the route MUST exist and MUST carry the exact security contract the
 |                   matrix specifies: middleware, permission, MFA, step-up, idempotency,
 |                   route class, and the platform_mutation forbidden middleware.
 |
 | An increment flips its operations to `implemented` as it lands, so the direction of the
 | assertion changes but its strength never does. There is no state in which an operation goes
 | unchecked, and no assertion is relaxed because implementation is missing.
 |
 | Forbidden-capability absence is asserted unconditionally, always, in
 | Ui08NoForbiddenPlatformCapabilityTest.
 */

/** @return array<string,mixed> */
function ui08SecurityMatrix(): array
{
    static $matrix = null;

    if ($matrix === null) {
        $matrix = json_decode(
            (string) file_get_contents(base_path('docs/backend/audits/ui-08/cor-ui08-001-contract-matrix.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    return $matrix;
}

/** @return list<array<string,mixed>> */
function ui08SecurityOperations(): array
{
    $operations = [];

    foreach (ui08SecurityMatrix()['domains'] as $domain) {
        foreach ($domain['operations'] as $operation) {
            $operation['__domain'] = $domain['domain'];
            $operations[] = $operation;
        }
    }

    return $operations;
}

function ui08RouteByName(string $name): ?Route
{
    foreach (RouteFacade::getRoutes() as $route) {
        if ($route->getName() === $name) {
            return $route;
        }
    }

    return null;
}

/**
 * Gathered middleware (group + route), aliases resolved to classes — the same view
 * RouteClass::requiredMiddleware() is matched against.
 *
 * @return list<string>
 */
function ui08GatheredMiddleware(Route $route): array
{
    /** @var list<string> $middleware */
    $middleware = app('router')->gatherRouteMiddleware($route);

    return $middleware;
}

function ui08HasMiddleware(Route $route, string $needle): bool
{
    foreach (ui08GatheredMiddleware($route) as $middleware) {
        if (str_contains($middleware, $needle)) {
            return true;
        }
    }

    return false;
}

// ---------------------------------------------------------------------------------------------
// Negative control — a planned operation has no route yet
// ---------------------------------------------------------------------------------------------

it('registers no route for an operation still specified as planned', function (): void {
    $leaked = [];

    foreach (ui08SecurityOperations() as $operation) {
        if ($operation['implementation_state'] !== 'planned') {
            continue;
        }

        if (ui08RouteByName($operation['operation_id']) !== null) {
            $leaked[] = $operation['operation_id'];
        }
    }

    expect($leaked)->toBe([],
        'these routes exist while the contract matrix still calls them planned — flip the matrix entry '
        .'to implemented in the same increment that registers the route, so its security contract is asserted',
    );
});

// ---------------------------------------------------------------------------------------------
// Positive control — an implemented operation carries its exact contract
// ---------------------------------------------------------------------------------------------

it('gives every implemented operation the exact security contract the matrix specifies', function (): void {
    $implemented = array_values(array_filter(
        ui08SecurityOperations(),
        static fn (array $operation): bool => $operation['implementation_state'] === 'implemented',
    ));

    if ($implemented === []) {
        // Increment 2b: nothing is implemented yet, so the negative control above is what has
        // force. Assert that explicitly rather than passing vacuously — every one of the 33
        // specified operations must currently be accounted for as planned.
        $planned = array_filter(
            ui08SecurityOperations(),
            static fn (array $operation): bool => $operation['implementation_state'] === 'planned',
        );

        expect($planned)->toHaveCount(33, 'every specified operation must be planned or implemented');

        return;
    }

    foreach ($implemented as $operation) {
        $id = $operation['operation_id'];
        $route = ui08RouteByName($id);

        expect($route)->not->toBeNull($id.' is marked implemented but no route carries that name');

        if ($route === null) {
            continue; // unreachable — the expectation above throws; explicit for the reader
        }

        // Path parity with the specification.
        expect('/'.$route->uri())->toBe($operation['path'], $id.' path drifted from the contract');
        // NOTE: Pest's toContain() is variadic — a second argument is another expected value,
        // not a message. Assert membership explicitly so the message survives.
        expect(in_array(strtoupper($operation['method']), $route->methods(), true))
            ->toBeTrue($id.' method drifted from the contract');

        // The platform group controls, on every operation.
        expect(ui08HasMiddleware($route, 'Authenticate'))->toBeTrue($id.' must require auth:sanctum');
        expect(ui08HasMiddleware($route, EnsurePrivilegedMfa::class))->toBeTrue($id.' must require MFA');
        expect(ui08HasMiddleware($route, ResolvePlatformContext::class))->toBeTrue($id.' must resolve platform context');
        expect(ui08HasMiddleware($route, 'ThrottleRequests'))->toBeTrue($id.' must be rate limited');

        // A platform route NEVER carries merchant tenant context (Plan §24.1, ADR-017).
        expect(ui08HasMiddleware($route, ResolveTenantContext::class))->toBeFalse($id.' must not resolve tenant context');
        expect(ui08HasMiddleware($route, EnsureMerchantActive::class))->toBeFalse($id.' must not gate on merchant activity');

        // The exact permission, not merely some permission.
        expect(ui08HasMiddleware($route, EnsurePermission::class.':'.$operation['permission']))
            ->toBeTrue($id.' must be gated by '.$operation['permission']);

        if ($operation['kind'] === 'read') {
            expect(ui08HasMiddleware($route, RequireFreshMfa::class))->toBeFalse($id.' is a read — no step-up');
            expect($route->defaults[RouteClassification::KEY] ?? null)->toBeNull($id.' is a read — no route class');

            continue;
        }

        // Mutations: route class, the exact step-up action, and idempotency.
        expect($route->defaults[RouteClassification::KEY] ?? null)
            ->toBe(RouteClass::PlatformMutation->value, $id.' must be classified platform_mutation');

        expect(ui08HasMiddleware($route, RequireFreshMfa::class.':'.$operation['step_up_action']))
            ->toBeTrue($id.' must require fresh step-up for '.$operation['step_up_action']);

        expect(ui08HasMiddleware($route, EnsureIdempotentRequest::class))
            ->toBeTrue($id.' must be idempotent');
    }
});

it('keeps every corrective route inside the platform prefix, and registers exactly the implemented ones', function (): void {
    $live = [];
    $misplaced = [];

    foreach (ui08SecurityOperations() as $operation) {
        $route = ui08RouteByName($operation['operation_id']);

        if ($route === null) {
            continue;
        }

        $live[] = $operation['operation_id'];

        if (! str_starts_with($route->uri(), 'api/v1/platform/')) {
            $misplaced[] = $operation['operation_id'].' -> '.$route->uri();
        }
    }

    expect($misplaced)->toBe([], 'a corrective route escaped the platform prefix');

    // The live set must be exactly the implemented set — never more, never fewer. This is what
    // makes the assertion non-vacuous while nothing is implemented: it asserts zero live routes.
    $implemented = array_values(array_map(
        static fn (array $operation): string => $operation['operation_id'],
        array_filter(
            ui08SecurityOperations(),
            static fn (array $operation): bool => $operation['implementation_state'] === 'implemented',
        ),
    ));

    sort($live);
    sort($implemented);

    expect($live)->toBe($implemented);
});
