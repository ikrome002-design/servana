<?php

declare(strict_types=1);

use App\Domain\Audit\Support\AuditMutationCoverage;
use Illuminate\Support\Facades\Route as RouteFacade;

uses()->group('audit', 'coverage');

/*
 | Phase 19 Increment 5 — enforced mutation→audit coverage guard (Plan §70, §80).
 | Every implemented mutating (non-GET) /api/v1 route MUST be classified in
 | AuditMutationCoverage as either AUDITED (→ a typed AuditEvent it emits) or
 | EXEMPT (with a reason). A new mutating route with no classification FAILS CI —
 | so an audited transition can never ship silently unaudited.
 */

/** Named, non-testing, non-GET api/v1 route names currently registered. @return list<string> */
function implementedMutationRouteNames(): array
{
    $names = [];

    foreach (RouteFacade::getRoutes() as $route) {
        $uri = $route->uri();
        $name = $route->getName();

        if (! str_starts_with($uri, 'api/v1')) {
            continue;
        }
        if ($name === null) {
            continue; // unnamed routes are not part of the classified contract
        }
        if (str_starts_with($name, 'testing.') || str_contains($uri, 'api/v1/testing/')) {
            continue;
        }
        if (array_diff($route->methods(), ['GET', 'HEAD']) === []) {
            continue; // GET/HEAD only — reads are not mutations
        }

        $names[$name] = true;
    }

    return array_keys($names);
}

it('classifies every implemented mutating route as AUDITED or EXEMPT (no unmapped mutation)', function (): void {
    $classified = AuditMutationCoverage::classifiedRoutes();

    $unmapped = array_values(array_diff(implementedMutationRouteNames(), $classified));

    expect($unmapped)->toBe([], 'Unmapped mutating route(s) — add to AuditMutationCoverage::AUDITED or EXEMPT: '.implode(', ', $unmapped));
});

it('keeps the coverage registry free of stale entries (every classified route still exists)', function (): void {
    $live = implementedMutationRouteNames();

    $stale = array_values(array_diff(AuditMutationCoverage::classifiedRoutes(), $live));

    expect($stale)->toBe([], 'Stale coverage entr(y/ies) — route(s) removed but still classified: '.implode(', ', $stale));
});

it('never classifies a route as both AUDITED and EXEMPT', function (): void {
    $overlap = array_values(array_intersect(
        AuditMutationCoverage::auditedRoutes(),
        AuditMutationCoverage::exemptRoutes(),
    ));

    expect($overlap)->toBe([], 'Route(s) both AUDITED and EXEMPT: '.implode(', ', $overlap));
});

it('references only real AuditEvent cases in the AUDITED map', function (): void {
    $valid = AuditMutationCoverage::validEventValues();

    $unknown = array_values(array_diff(AuditMutationCoverage::referencedEvents(), $valid));

    expect($unknown)->toBe([], 'AUDITED references non-existent AuditEvent action(s): '.implode(', ', $unknown));
});

it('requires a non-empty reason for every EXEMPT mutation', function (): void {
    foreach (AuditMutationCoverage::EXEMPT as $route => $reason) {
        expect($reason)->toBeString()->not->toBe('', "EXEMPT route {$route} must carry a reason");
    }
});
