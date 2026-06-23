<?php

declare(strict_types=1);

use App\Http\Routing\RouteClass;
use App\Http\Routing\RouteClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

uses()->group('security', 'route-contract');

/*
 | Route security contract (Plan §24.1, §24.2; Phase 10 REM-ROUTE-001). Builds on
 | the R4 idempotency seam (RouteClass / RouteClassification) without replacing it.
 | This suite is the structural guard that every non-GET route declares exactly one
 | valid classification and carries the middleware controls its class mandates.
 |
 | The idempotency coverage guard itself stays in FinancialRouteIdempotencyCoverageTest
 | (reused, not duplicated); forbidden-route absence stays in ForbiddenRouteAbsenceTest.
 */

/** All API + health routes (production and, under testing, the harness routes). */
function contractRoutes(): array
{
    $routes = [];

    foreach (RouteFacade::getRoutes() as $route) {
        $uri = $route->uri();

        if (str_starts_with($uri, 'api/v1') || str_starts_with($uri, 'health')) {
            $routes[] = $route;
        }
    }

    return $routes;
}

function isNonGet(Route $route): bool
{
    return (bool) array_diff($route->methods(), ['GET', 'HEAD']);
}

function isTestingRoute(Route $route): bool
{
    $name = $route->getName() ?? '';

    return str_starts_with($name, 'testing.') || str_contains($route->uri(), 'api/v1/testing/');
}

/** True when the controller action type-hints a FormRequest (validates a body). */
function routeValidatesBody(Route $route): bool
{
    $action = $route->getActionName();

    if (! str_contains($action, '@')) {
        return false; // closure (test harness only)
    }

    [$class, $method] = explode('@', $action, 2);

    if (! class_exists($class) || ! method_exists($class, $method)) {
        return false;
    }

    foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType
            && ! $type->isBuiltin()
            && is_subclass_of($type->getName(), FormRequest::class)) {
            return true;
        }
    }

    return false;
}

it('classifies every non-GET route with exactly one valid class', function (): void {
    $unclassified = [];

    foreach (contractRoutes() as $route) {
        if (! isNonGet($route)) {
            continue;
        }

        $value = $route->defaults[RouteClassification::KEY] ?? null;

        // A single canonical string that resolves to one RouteClass. An array
        // (multiple classifications) or unknown string fails here.
        if (! is_string($value) || RouteClass::tryFrom($value) === null) {
            $unclassified[] = ($route->getName() ?? $route->uri()).' => '.var_export($value, true);
        }
    }

    expect($unclassified)->toBe([]);
});

it('enforces the required middleware for each route class', function (): void {
    $violations = [];

    foreach (contractRoutes() as $route) {
        if (RouteClassification::of($route) === null) {
            continue;
        }

        $missing = RouteClassification::requiredMiddlewareMissing($route);

        if ($missing !== []) {
            $violations[] = ($route->getName() ?? $route->uri()).' missing '.implode(',', $missing);
        }
    }

    expect($violations)->toBe([]);
});

it('forbids class-incompatible middleware (public/auth-global tenant, platform merchant, webhook sanctum)', function (): void {
    $violations = [];

    foreach (contractRoutes() as $route) {
        if (RouteClassification::of($route) === null) {
            continue;
        }

        $present = RouteClassification::forbiddenMiddlewarePresent($route);

        if ($present !== []) {
            $violations[] = ($route->getName() ?? $route->uri()).' carries forbidden '.implode(',', $present);
        }
    }

    expect($violations)->toBe([]);
});

it('requires every financial_mutation route to carry idempotency', function (): void {
    // The authoritative guard lives in FinancialRouteIdempotencyCoverageTest; this
    // is the contract-level restatement so a financial route can never exist here
    // without idempotency.
    $missing = RouteClassification::financialRoutesMissingIdempotency(RouteFacade::getRoutes());

    expect($missing)->toBe([]);
});

it('validates the request body of every mutation (or records an explicit exemption)', function (): void {
    $violations = [];

    foreach (contractRoutes() as $route) {
        $class = RouteClassification::of($route);

        if ($class === null || ! $class->requiresValidation() || ! isNonGet($route)) {
            continue;
        }

        // Closure harness routes (testing only) carry no controller to reflect.
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            continue;
        }

        $name = $route->getName() ?? $route->uri();

        if (routeValidatesBody($route)) {
            continue;
        }

        if (array_key_exists($name, RouteClassification::VALIDATION_EXEMPT)) {
            continue; // explicit, reasoned exemption (bodiless mutation)
        }

        $violations[] = $name;
    }

    expect($violations)->toBe([]);
});

it('namespaces every test-only route under the env-gated testing prefix', function (): void {
    // Production exclusion is additionally enforced by OpenApiContractTest (the
    // generated production inventory must contain no testing route). Here we prove
    // the convention: a test-only route is identifiable and gated, and no
    // production route hides under the testing prefix.
    $leaks = [];

    foreach (contractRoutes() as $route) {
        $name = $route->getName() ?? '';
        $underPrefix = str_contains($route->uri(), 'api/v1/testing/');
        $namedTesting = str_starts_with($name, 'testing.');

        // Either both markers or neither — never a half-named production route
        // under the testing prefix, or a testing-named route outside it.
        if ($underPrefix !== $namedTesting) {
            $leaks[] = ($name !== '' ? $name : $route->uri());
        }
    }

    expect($leaks)->toBe([]);
});

it('keeps the validation-exemption allowlist free of stale entries', function (): void {
    $names = [];

    foreach (contractRoutes() as $route) {
        if ($name = $route->getName()) {
            $names[$name] = true;
        }
    }

    $stale = array_values(array_filter(
        array_keys(RouteClassification::VALIDATION_EXEMPT),
        fn (string $name): bool => ! isset($names[$name]),
    ));

    expect($stale)->toBe([]);
});
