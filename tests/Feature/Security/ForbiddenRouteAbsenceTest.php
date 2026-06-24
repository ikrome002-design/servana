<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route as RouteFacade;

uses()->group('security', 'route-contract');

/*
 | Forbidden-route absence (Plan §10.2, §23.1; ADR-010; Guardrail 8). Two routes
 | must never exist anywhere in Servana:
 |   1. A Super-Administrator / platform merchant-creation route — merchants are
 |      created only by self-registration (Scope §3.1); there is no admin-creates-
 |      merchant channel.
 |   2. A Merchant Personnel contact-export route — no schema field, no endpoint,
 |      no UI (Guardrail 8; ADR-010). Guessed export-shaped routes must 404.
 |
 | This is the canonical absence guard; RouteSecurityContractTest delegates to it.
 */

/** @return array<int, array{name: string, uri: string, methods: string}> */
function allRouteDescriptors(): array
{
    $out = [];

    foreach (RouteFacade::getRoutes() as $route) {
        $out[] = [
            'name' => $route->getName() ?? '',
            'uri' => $route->uri(),
            'methods' => implode('|', array_diff($route->methods(), ['HEAD'])),
        ];
    }

    return $out;
}

it('exposes no Super-Administrator / platform merchant-creation route', function (): void {
    $offending = [];

    foreach (allRouteDescriptors() as $r) {
        $writes = str_contains($r['methods'], 'POST')
            || str_contains($r['methods'], 'PUT')
            || str_contains($r['methods'], 'PATCH');

        if (! $writes) {
            continue;
        }

        // A write to a `merchants` collection/resource is the forbidden shape.
        // The permitted public flow is `merchant-registration.self-register`
        // (self-service), which targets `merchant-registration`, not `merchants`.
        $nameSaysCreate = (bool) preg_match('#(^|\.)merchants?\.(store|create)$#', $r['name']);
        $platformCreate = (bool) preg_match('#^api/v1/(platform/)?merchants/?$#', $r['uri']);

        if ($nameSaysCreate || $platformCreate) {
            $offending[] = $r['methods'].' '.$r['uri'].' ['.$r['name'].']';
        }
    }

    expect($offending)->toBe([]);
});

it('exposes no Merchant Personnel contact-export route anywhere', function (): void {
    $offending = [];

    foreach (allRouteDescriptors() as $r) {
        $haystack = strtolower($r['name'].' '.$r['uri']);

        $contactExport = str_contains($haystack, 'contact-export')
            || str_contains($haystack, 'contacts/export')
            || (str_contains($haystack, 'personnel') && str_contains($haystack, 'export'))
            || (str_contains($haystack, 'personnel') && str_contains($haystack, 'contact'));

        if ($contactExport) {
            $offending[] = $r['methods'].' '.$r['uri'].' ['.$r['name'].']';
        }
    }

    expect($offending)->toBe([]);
});
