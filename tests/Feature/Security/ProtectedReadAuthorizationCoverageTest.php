<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;

uses()->group('security', 'phase23', 'protected-read');

/*
 |==============================================================================
 | Phase 23 Increment 3.1 — protected-READ authorization coverage (Plan §9 rule 4, §24).
 |
 | `RouteSecurityContractTest` classifies NON-GET routes only, so until Phase 23 nothing
 | mechanically proved that a read route had any server-side authority at all. That gap is
 | exactly how `GET /api/v1/staff` shipped with no `EnsurePermission` and no `authorize()`
 | call while returning unmasked personnel phone numbers (PH23-SEC-001).
 |
 | This guard enumerates the LIVE route table and requires every `api/v1` read route to carry
 | one of the boundary forms below. It deliberately does NOT demand identical middleware
 | everywhere — Plan §24 allows different classes of read — it demands EQUIVALENT server-side
 | authority, named and evidenced.
 |
 | What is NEVER an acceptable boundary (Plan §9 preamble, §81 rule 20):
 |   navigation visibility · Vue route metadata · hidden buttons · frontend `can` maps ·
 |   a comment claiming a policy runs when it does not.
 */

/** Boundary kinds, strongest first. */
const P23_READ_BOUNDARIES = [
    'permission_middleware',   // EnsurePermission:<key>
    'branch_scope_middleware', // EnsureBranchScope — foreign/unassigned branch → 404/403 before the controller
    'controller_authorization', // $this->authorize(), Gate::, a policy object, or an authorization service
    'documented_exception',    // explicitly enumerated below, with a reason
];

/**
 * Reads whose authority is real but not expressible as middleware or an `authorize()` call.
 * Every entry must name WHY the route is safe. A route may only appear here deliberately.
 *
 * @var array<string, string> route name => documented boundary
 */
const P23_DOCUMENTED_READ_EXCEPTIONS = [
    'me' => 'Authenticated-self bootstrap (Plan §6.2). Returns only the caller\'s own identity, memberships, resolved permissions and branch ids — there is no other subject to authorize against. Tenant context is resolved, never accepted from input.',
    'auth.mfa.status' => 'Authenticated-self MFA state (Plan §18). Returns only the caller\'s own enrollment/challenge flags — never a secret, never another user\'s state.',
    'search.index' => 'Documented permission INTERSECTION (Plan §68; decision D-22-01). The aggregator grants access to no document type: each type is admitted only when the caller already holds that type\'s own list/detail authority, and every record re-passes that type\'s policy. A caller with no searchable authority receives 200 + an empty collection — a 403 would be an existence oracle over the catalogue.',
    'branches.index' => 'Policy-gated scoped query (Plan §8.2). Returns only the caller\'s own merchant, narrowed to assigned branches for a branch-scoped membership; a merchant-wide membership legitimately sees all own-merchant branches. There is no unscoped read path and no caller-supplied scope parameter.',
    'merchant.dashboard' => 'Authenticated own-merchant summary behind ResolveTenantContext + EnsureMerchantActive (Plan §8.1). The merchant is resolved from the caller\'s membership and never accepted from input, so there is no foreign subject to authorize against; a caller with no active merchant is aborted before any data is read.',
];

/**
 * Routes whose boundary kind is pinned so a later refactor cannot silently downgrade them.
 * These are the surfaces Phase 23 hardened or that carry contact/financial data.
 *
 * @var array<string, string> route name => required boundary kind
 */
const P23_PINNED_READ_BOUNDARIES = [
    // PH23-SEC-001: both staff reads must keep a real authorization call.
    'staff.index' => 'controller_authorization',
    'staff.show' => 'controller_authorization',
    // The Phase 23 narrow Branch Manager option source must stay permission-gated.
    'branch.personnel-options.index' => 'permission_middleware',
    // File reads must keep the FileAccessService boundary (Plan §65).
    'files.show' => 'controller_authorization',
    'files.download' => 'controller_authorization',
];

/**
 * Classify a live GET route's server-side authorization boundary.
 *
 * @return array{kind: string|null, detail: string}
 */
function p23ReadBoundary(Illuminate\Routing\Route $route): array
{
    $middleware = implode('|', $route->gatherMiddleware());

    if (str_contains($middleware, 'EnsurePermission')) {
        return ['kind' => 'permission_middleware', 'detail' => 'EnsurePermission'];
    }

    if (str_contains($middleware, 'EnsureBranchScope')) {
        return ['kind' => 'branch_scope_middleware', 'detail' => 'EnsureBranchScope'];
    }

    // A dedicated access-gate middleware is an equally real server-side boundary: it decides
    // eligibility before the controller runs (Plan §24.1 class-specific controls).
    if (str_contains($middleware, 'EnsureFirstTimeSetupAccess')) {
        return ['kind' => 'branch_scope_middleware', 'detail' => 'EnsureFirstTimeSetupAccess'];
    }

    $action = $route->getActionName();
    if (str_contains($action, '@')) {
        [$class, $method] = explode('@', $action);
        if (class_exists($class)) {
            $reflection = new ReflectionClass($class);
            $source = (string) file_get_contents((string) $reflection->getFileName());

            // A policy/gate call anywhere in the controller (methods often delegate to a private
            // helper, e.g. StaffController::authorizeScoped or FileController's access service).
            if (preg_match('/\$this->authorize\(|Gate::|Policy::class\)->|->authorizeView\(|->authorizeDownload\(|->authorizeScoped\(/', $source)) {
                return ['kind' => 'controller_authorization', 'detail' => 'policy/gate/authorization-service call'];
            }

            // An explicit permission assertion in the controller is a real boundary too — the
            // own-scope personnel reads use `abort_unless($this->context->can('<key>'), 403)`
            // rather than a policy object, because the subject IS the caller's own membership.
            // Only a permission-bearing abort counts; a bare null/context abort does not.
            if (preg_match('/abort_(unless|if)\s*\(\s*!?\s*\$this->context->can\(|abort_(unless|if)\s*\(\s*!?\s*\$\w+->can\(/', $source)) {
                return ['kind' => 'controller_authorization', 'detail' => 'abort_unless(context->can(...))'];
            }

            // A Form Request whose authorize() consults a permission or policy.
            $reflectionMethod = new ReflectionMethod($class, $method);
            foreach ($reflectionMethod->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (! $type instanceof ReflectionNamedType || ! class_exists($type->getName())) {
                    continue;
                }
                if (! is_subclass_of($type->getName(), FormRequest::class)) {
                    continue;
                }
                $requestSource = (string) file_get_contents((string) (new ReflectionClass($type->getName()))->getFileName());
                if (preg_match('/function authorize\(\).*?(->can\(|Gate::|->allows\(|Policy)/s', $requestSource)) {
                    return ['kind' => 'controller_authorization', 'detail' => 'FormRequest::authorize()'];
                }
            }
        }
    }

    return ['kind' => null, 'detail' => 'no server-side authorization boundary found'];
}

/** @return list<Illuminate\Routing\Route> every shipped api/v1 read route */
function p23ReadRoutes(): array
{
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/')) {
            continue;
        }
        // The Phase R3 test-only security harness is never registered outside `testing`.
        if (str_starts_with($uri, 'api/v1/testing/')) {
            continue;
        }
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }
        $routes[] = $route;
    }

    return $routes;
}

it('gives every shipped api/v1 read route a server-side authorization boundary', function (): void {
    $unprotected = [];

    foreach (p23ReadRoutes() as $route) {
        $name = (string) $route->getName();
        $boundary = p23ReadBoundary($route);

        if ($boundary['kind'] !== null) {
            continue;
        }

        if (array_key_exists($name, P23_DOCUMENTED_READ_EXCEPTIONS)) {
            continue;
        }

        $unprotected[] = sprintf('%s (%s) — %s', $name !== '' ? $name : '(unnamed)', $route->uri(), $boundary['detail']);
    }

    expect($unprotected)->toBe([], implode("\n", array_merge(
        ['Read routes with NO server-side authorization boundary:'],
        $unprotected,
        ['', 'Add EnsurePermission, a policy/authorize() call, or an explicitly documented entry in P23_DOCUMENTED_READ_EXCEPTIONS.'],
    )));
});

it('keeps the documented read exceptions honest — every entry is a live route', function (): void {
    $live = [];
    foreach (p23ReadRoutes() as $route) {
        $live[(string) $route->getName()] = true;
    }

    $stale = [];
    foreach (array_keys(P23_DOCUMENTED_READ_EXCEPTIONS) as $name) {
        if (! isset($live[$name])) {
            $stale[] = $name;
        }
    }

    expect($stale)->toBe([], 'Documented read exceptions that no longer exist (delete them): '.implode(', ', $stale));

    // Every exception must carry a substantive reason, not a placeholder.
    foreach (P23_DOCUMENTED_READ_EXCEPTIONS as $name => $reason) {
        expect(strlen($reason))->toBeGreaterThan(80, "{$name}: the documented boundary must explain WHY the route is safe");
    }
});

it('pins the boundary kind of the contact-bearing and Phase 23 hardened reads', function (): void {
    $byName = [];
    foreach (p23ReadRoutes() as $route) {
        $byName[(string) $route->getName()] = $route;
    }

    $problems = [];
    foreach (P23_PINNED_READ_BOUNDARIES as $name => $requiredKind) {
        if (! isset($byName[$name])) {
            $problems[] = "{$name}: pinned read route is missing from the route table";

            continue;
        }

        $actual = p23ReadBoundary($byName[$name]);
        if ($actual['kind'] !== $requiredKind) {
            $problems[] = sprintf('%s: boundary downgraded — expected %s, found %s', $name, $requiredKind, $actual['kind'] ?? 'NONE');
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('never accepts a frontend-only signal as a read boundary', function (): void {
    // A read route must not be "protected" purely by navigation metadata. Assert the two
    // Phase 23 surfaces resolve to real backend authority, not to a route-name lookup.
    $byName = [];
    foreach (p23ReadRoutes() as $route) {
        $byName[(string) $route->getName()] = $route;
    }

    expect($byName)->toHaveKey('staff.index');
    expect($byName)->toHaveKey('branch.personnel-options.index');

    // The staff roster carries `phone`; its boundary must be an authorization call, and the
    // route must NOT be reachable on tenant scoping alone.
    $staff = p23ReadBoundary($byName['staff.index']);
    expect($staff['kind'])->toBe('controller_authorization');

    // The narrow option source must be permission-gated, never merely branch-scoped.
    $options = p23ReadBoundary($byName['branch.personnel-options.index']);
    expect($options['kind'])->toBe('permission_middleware');
});

it('reports the read-boundary distribution so coverage is visible, not assumed', function (): void {
    $counts = array_fill_keys(P23_READ_BOUNDARIES, 0);

    foreach (p23ReadRoutes() as $route) {
        $boundary = p23ReadBoundary($route);
        $kind = $boundary['kind'] ?? 'documented_exception';
        $counts[$kind] = ($counts[$kind] ?? 0) + 1;
    }

    $total = array_sum($counts);

    // Every read route lands in exactly one bucket — no route is unaccounted for.
    expect($total)->toBe(count(p23ReadRoutes()));
    expect($counts['documented_exception'])->toBe(count(P23_DOCUMENTED_READ_EXCEPTIONS));

    // A tripwire: the documented-exception list must stay small. Growing it is a design smell
    // and should be a deliberate, reviewed decision rather than a quiet accumulation.
    expect($counts['documented_exception'])->toBeLessThanOrEqual(6);
});
