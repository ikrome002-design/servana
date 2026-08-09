<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route as RouteFacade;

uses()->group('security', 'route-contract', 'ui08');

/*
 |==============================================================================
 | Phase UI-08 — the Super Administrator's hard capability boundaries.
 |
 | The Super Administrator GOVERNS existing merchants. It never operates one, never joins one,
 | never creates one, never impersonates a user inside one, never moves money and never touches
 | partner-owned truth (Plan §10.2; ADR-012/013; COR-UI08-001 "Security boundaries").
 |
 | ForbiddenRouteAbsenceTest is the canonical guard for the two oldest absences (platform
 | merchant creation, personnel contact export) and is NOT duplicated here. This suite covers
 | the capability surface COR-UI08-001 opens, and it asserts UNCONDITIONALLY — before, during
 | and after implementation — so no corrective increment can quietly add one of these.
 |
 | It also asserts the explicit forbidden-operation list the contract matrix enumerates, so the
 | specification and the runtime cannot disagree about what must not exist.
 */

/** @return list<array{name:string,uri:string,methods:string}> */
function ui08AllRoutes(): array
{
    $routes = [];

    foreach (RouteFacade::getRoutes() as $route) {
        $routes[] = [
            'name' => $route->getName() ?? '',
            'uri' => $route->uri(),
            'methods' => implode('|', array_diff($route->methods(), ['HEAD'])),
        ];
    }

    return $routes;
}

function ui08WritesData(array $route): bool
{
    return str_contains($route['methods'], 'POST')
        || str_contains($route['methods'], 'PUT')
        || str_contains($route['methods'], 'PATCH')
        || str_contains($route['methods'], 'DELETE');
}

it('exposes no impersonation route anywhere', function (): void {
    $offending = [];

    foreach (ui08AllRoutes() as $route) {
        if (preg_match('#impersonat|masquerad|act[-_]as|sudo#i', $route['uri'].' '.$route['name']) === 1) {
            $offending[] = $route['methods'].' '.$route['uri'].' ['.$route['name'].']';
        }
    }

    expect($offending)->toBe([], 'the Super Administrator may never assume a merchant user identity');
});

it('exposes no platform route that creates merchant membership, branch assignment or a staff profile', function (): void {
    /*
     | Matched on whole PATH SEGMENTS, not substrings. A substring match is wrong here and was
     | caught by this suite on its first run: `platform/preferred-personnel-fee-rules` is a Phase
     | 20A *pricing* surface that merely contains the word "personnel", and flagging it would have
     | been a false positive against a legitimate, shipped, reviewed route. The forbidden thing is
     | a route addressing one of the merchant STRUCTURES by name.
     */
    $forbiddenSegments = [
        'members', 'memberships', 'merchant-users', 'staff', 'staff-profiles',
        'personnel', 'branches', 'branch-assignments', 'assignments',
    ];

    $offending = [];

    foreach (ui08AllRoutes() as $route) {
        if (! str_starts_with($route['uri'], 'api/v1/platform/') || ! ui08WritesData($route)) {
            continue;
        }

        $segments = explode('/', $route['uri']);

        // The one permitted platform invitation surface is internal platform access, which grants
        // platform access ONLY and can never write a merchant structure.
        if (str_contains($route['uri'], 'platform/internal-access/')) {
            continue;
        }

        if (array_intersect($segments, $forbiddenSegments) !== []) {
            $offending[] = $route['methods'].' '.$route['uri'].' ['.$route['name'].']';
        }

        // An invitation surface anywhere else under /platform would be a merchant-staff channel.
        if (in_array('invitations', $segments, true)) {
            $offending[] = $route['methods'].' '.$route['uri'].' ['.$route['name'].']';
        }
    }

    expect($offending)->toBe([]);
});

it('exposes no platform subscription or invoice mutation', function (): void {
    $offending = [];

    foreach (ui08AllRoutes() as $route) {
        if (! str_starts_with($route['uri'], 'api/v1/platform/') || ! ui08WritesData($route)) {
            continue;
        }

        if (preg_match('#subscription|invoice|billing-credit#i', $route['uri']) === 1) {
            $offending[] = $route['methods'].' '.$route['uri'].' ['.$route['name'].']';
        }
    }

    expect($offending)->toBe([],
        'subscription operations is monitoring only — no mutation, no manual payment, no credit write',
    );
});

it('exposes no manual payment recording on any platform route', function (): void {
    $offending = [];

    foreach (ui08AllRoutes() as $route) {
        if (! str_starts_with($route['uri'], 'api/v1/platform/') || ! ui08WritesData($route)) {
            continue;
        }

        if (preg_match('#payment|receipt|settle|collect#i', $route['uri'].' '.$route['name']) === 1) {
            $offending[] = $route['methods'].' '.$route['uri'].' ['.$route['name'].']';
        }
    }

    expect($offending)->toBe([], 'money movement is Wallet truth (ADR-012); Servana records no platform payment');
});

it('exposes no direct provider integration route', function (): void {
    $offending = [];

    foreach (ui08AllRoutes() as $route) {
        if (preg_match('#daraja|safaricom|stk[-_]?push|provider[-_]credential#i', $route['uri'].' '.$route['name']) === 1) {
            $offending[] = $route['methods'].' '.$route['uri'].' ['.$route['name'].']';
        }
    }

    expect($offending)->toBe([]);
});

it('exposes no Refer & Earn reward calculation or payout route', function (): void {
    $offending = [];

    foreach (ui08AllRoutes() as $route) {
        if (! ui08WritesData($route)) {
            continue;
        }

        if (preg_match('#reward#i', $route['uri'].' '.$route['name']) === 1) {
            $offending[] = $route['methods'].' '.$route['uri'].' ['.$route['name'].']';
        }
    }

    expect($offending)->toBe([], 'reward calculation, ledger and payout are Refer & Earn truth (ADR-013)');
});

it('exposes no route that opens or bypasses an external gate', function (): void {
    $offending = [];

    foreach (ui08AllRoutes() as $route) {
        if (! ui08WritesData($route)) {
            continue;
        }

        if (preg_match('#(^|/)gates?(/|$)#i', $route['uri']) === 1) {
            $offending[] = $route['methods'].' '.$route['uri'].' ['.$route['name'].']';
        }
    }

    expect($offending)->toBe([], 'External Gate W is an evidence-based launch gate, never an API');
});

it('exposes no SMS recipient, contact or message-body route on the platform surface', function (): void {
    $offending = [];

    foreach (ui08AllRoutes() as $route) {
        if (! str_starts_with($route['uri'], 'api/v1/platform/')) {
            continue;
        }

        if (preg_match('#recipient|contact|phone|message-body|export#i', $route['uri']) === 1) {
            $offending[] = $route['methods'].' '.$route['uri'].' ['.$route['name'].']';
        }
    }

    expect($offending)->toBe([],
        'the platform SMS surface is aggregate-only; personnel contact export does not exist anywhere (guardrail 8, ADR-010)',
    );
});

it('registers none of the forbidden operations the contract matrix enumerates', function (): void {
    $matrix = json_decode(
        (string) file_get_contents(base_path('docs/backend/audits/ui-08/cor-ui08-001-contract-matrix.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $live = [];
    foreach (ui08AllRoutes() as $route) {
        foreach (explode('|', $route['methods']) as $method) {
            $live[] = $method.' /'.$route['uri'];
        }
    }

    $offending = [];
    foreach ($matrix['forbidden_operations'] as $forbidden) {
        // Compare on the literal shape the matrix records, with route parameters normalized.
        $normalized = preg_replace('#\{[^}]+\}#', '{param}', $forbidden);

        foreach ($live as $liveRoute) {
            if (preg_replace('#\{[^}]+\}#', '{param}', $liveRoute) === $normalized) {
                $offending[] = $forbidden;
            }
        }
    }

    expect($offending)->toBe([]);
});
