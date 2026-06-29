<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Http\Middleware\EnsureIdempotentRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Route-classification registry (Plan §24.1, §24.4; Phase R4 seam + Phase 10
 * REM-ROUTE-001 completion).
 *
 * A route declares its class in route defaults under {@see KEY}; this class is the
 * single source of truth for (a) the idempotency coverage guard the R4 phase
 * shipped ({@see financialRoutesMissingIdempotency()}, kept intact), and (b) the
 * full per-class middleware contract Phase 10 enforces
 * ({@see requiredMiddlewareMissing()} / {@see forbiddenMiddlewarePresent()}).
 *
 * The VALIDATION_EXEMPT allowlist is the *one* explicit place a mutation route may
 * opt out of body validation, with a written reason — used by the
 * RouteSecurityContractTest feature test.
 */
final class RouteClassification
{
    /** Route-defaults key carrying the {@see RouteClass} value. */
    public const KEY = 'route_class';

    /**
     * Mutation routes that legitimately accept no request body, so a Form Request
     * would validate nothing. Each entry is an explicit, reviewed exception with a
     * reason; authorization for these routes is enforced by policy/permission
     * middleware (not body validation). Keyed by route name.
     *
     * @var array<string, string>
     */
    public const VALIDATION_EXEMPT = [
        'auth.logout' => 'No request body; tears down the authenticated session.',
        'auth.mfa.enroll' => 'No request body; provisions and returns a new TOTP secret/Qns.',
        'auth.mfa.recovery-codes.regenerate' => 'No request body; gated by RequireFreshMfa step-up.',
        'branches.archive' => 'No request body; state transition authorized by branches.create permission + BranchPolicy.',
        'branches.day.open' => 'No request body; authorized by day.open_close permission.',
        'branches.day.close' => 'No request body; authorized by day.open_close permission.',
        'staff-invitations.resend' => 'No request body; {invitation} binding + StaffInvitationPolicy.',
        'staff-invitations.revoke' => 'No request body; {invitation} binding + StaffInvitationPolicy.',
        'staff.suspend' => 'No request body; {staff} binding + StaffProfilePolicy.',
        'staff.activate' => 'No request body; {staff} binding + StaffProfilePolicy.',
        'staff.deactivate' => 'No request body; {staff} binding + StaffProfilePolicy.',
        'staff.permissions.destroy' => 'No request body; {staff}+{permission} bindings + MerchantUserPolicy.',
        'files.download-link' => 'No request body; issues a signed link, authorized by FileAccessService.',
        'services.archive' => 'No request body; state transition authorized by service.archive permission + ServicePolicy.',
        'services.eligibility.destroy' => 'No request body; {service}+{staff} bindings + personnel.eligibility.manage.',
        'appointments.check-in' => 'No request body; {appointment} binding + appointment.check_in + AppointmentPolicy; branch-day-open enforced in the action.',
        'appointments.no-show' => 'No request body; {appointment} binding + appointment.cancel + AppointmentPolicy; distinct MarkAppointmentNoShow action.',
    ];

    public static function of(Route $route): ?RouteClass
    {
        $value = $route->defaults[self::KEY] ?? null;

        return is_string($value) ? RouteClass::tryFrom($value) : null;
    }

    /**
     * Required middleware (substrings) that this route's class mandates but the
     * route is missing. Empty when fully compliant.
     *
     * @return list<string>
     */
    public static function requiredMiddlewareMissing(Route $route): array
    {
        $class = self::of($route);

        if ($class === null) {
            return [];
        }

        $gathered = self::gathered($route);
        $missing = [];

        foreach ($class->requiredMiddleware() as $needle) {
            if (! self::contains($gathered, $needle)) {
                $missing[] = $needle;
            }
        }

        return $missing;
    }

    /**
     * Forbidden middleware (substrings) that this route's class bans but the route
     * carries anyway. Empty when fully compliant.
     *
     * @return list<string>
     */
    public static function forbiddenMiddlewarePresent(Route $route): array
    {
        $class = self::of($route);

        if ($class === null) {
            return [];
        }

        $gathered = self::gathered($route);
        $present = [];

        foreach ($class->forbiddenMiddleware() as $needle) {
            if (self::contains($gathered, $needle)) {
                $present[] = $needle;
            }
        }

        return $present;
    }

    /**
     * Names of `financial_mutation` routes that are missing the idempotency
     * middleware. Empty when every financial route is protected. (Kept from the
     * R4 seam — the FinancialRouteIdempotencyCoverageTest feature test.)
     *
     * @param  iterable<Route>  $routes
     * @return list<string>
     */
    public static function financialRoutesMissingIdempotency(iterable $routes): array
    {
        $missing = [];

        foreach ($routes as $route) {
            if (self::of($route) !== RouteClass::FinancialMutation) {
                continue;
            }

            if (! self::contains(self::gathered($route), EnsureIdempotentRequest::class)) {
                $missing[] = $route->getName() ?? $route->uri();
            }
        }

        return $missing;
    }

    /**
     * The gathered middleware for a route (group + route, aliases resolved).
     *
     * @return list<string>
     */
    private static function gathered(Route $route): array
    {
        $router = app(Router::class);

        return array_values(array_filter(
            $router->gatherRouteMiddleware($route),
            'is_string',
        ));
    }

    /**
     * @param  list<string>  $gathered
     */
    private static function contains(array $gathered, string $needle): bool
    {
        foreach ($gathered as $middleware) {
            if (str_contains($middleware, $needle)) {
                return true;
            }
        }

        return false;
    }
}
