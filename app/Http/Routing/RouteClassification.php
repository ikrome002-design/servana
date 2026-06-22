<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Http\Middleware\EnsureIdempotentRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Route-classification seam (Plan §24.1, §24.4; Phase R4).
 *
 * The minimal mechanism the idempotency coverage guard needs: a route declares
 * its class in route defaults, and {@see financialRoutesMissingIdempotency()}
 * fails when any `financial_mutation` route lacks the idempotency middleware.
 * Phase 10 builds the full RouteSecurityContractTest on top of this KEY.
 */
final class RouteClassification
{
    /** Route-defaults key carrying the {@see RouteClass} value. */
    public const KEY = 'route_class';

    public static function of(Route $route): ?RouteClass
    {
        $value = $route->defaults[self::KEY] ?? null;

        return is_string($value) ? RouteClass::tryFrom($value) : null;
    }

    /**
     * Names of `financial_mutation` routes that are missing the idempotency
     * middleware. Empty when every financial route is protected.
     *
     * @param  iterable<Route>  $routes
     * @return list<string>
     */
    public static function financialRoutesMissingIdempotency(iterable $routes): array
    {
        $router = app(Router::class);
        $missing = [];

        foreach ($routes as $route) {
            if (self::of($route) !== RouteClass::FinancialMutation) {
                continue;
            }

            if (! self::hasIdempotencyMiddleware($router, $route)) {
                $missing[] = $route->getName() ?? $route->uri();
            }
        }

        return $missing;
    }

    private static function hasIdempotencyMiddleware(Router $router, Route $route): bool
    {
        foreach ($router->gatherRouteMiddleware($route) as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, EnsureIdempotentRequest::class)) {
                return true;
            }
        }

        return false;
    }
}
