<?php

declare(strict_types=1);

use App\Http\Hosts\AccountHostRegistry;
use App\Http\Middleware\ResolveAccountHost;
use Illuminate\Support\Facades\Route;

uses()->group('hosts', 'ui02', 'security');

/*
 |==============================================================================
 | Phase UI-02 — machine traffic is independent of browser account hosts
 | (UI/UX plan §4.7; ADR-012, ADR-013).
 |
 | Partner webhooks (Wallet by Citrus, Citrus Refer & Earn), queue workers, the scheduler
 | and health probes must not acquire — or require — a browser account host. Introducing
 | eight hosts must not make a background job depend on a hostname, and must not let a
 | browser host imply a partner or platform identity.
 */

it('answers health probes without any account host', function (): void {
    // Container liveness addresses the edge by its own name, which is not an account host.
    $this->get('http://localhost/health')->assertOk()->assertJsonPath('status', 'ok');
    $this->get('http://127.0.0.1/health')->assertOk();
});

it('answers health probes identically on every account host', function (): void {
    foreach (app(AccountHostRegistry::class)->allHosts() as $host) {
        $this->get('http://'.$host.'/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }
});

it('reports host context safely and without leaking anything', function (): void {
    $response = $this->getJson('http://finance.servana.test/health/host');

    $response->assertOk()
        ->assertJsonPath('account_key', 'merchant_finance')
        ->assertJsonPath('requested_host', 'finance.servana.test')
        ->assertJsonPath('machine_host', false);

    /** @var array<string, mixed> $payload */
    $payload = $response->json();

    // Only safe operational metadata. No identity, tenant, permission, token, payment
    // reference or private infrastructure detail.
    expect(array_keys($payload))->toEqualCanonicalizing([
        'status', 'service', 'requested_host', 'account_key', 'machine_host', 'environment',
    ]);
});

it('reports an unknown host as unknown rather than guessing', function (): void {
    $this->getJson('http://attacker.test/health/host')
        ->assertStatus(421)
        ->assertJsonPath('status', 'unknown_host')
        ->assertJsonPath('account_key', null);
});

it('flags a machine host as a machine host, never as an account', function (): void {
    $response = $this->getJson('http://localhost/health/host');

    $response->assertStatus(421)
        ->assertJsonPath('account_key', null)
        ->assertJsonPath('machine_host', true);
});

it('keeps the API surface reachable on every account host', function (): void {
    // The API must behave identically regardless of which browser host fronts it — the
    // account host selects an experience, never a business rule (ADR-017).
    foreach (app(AccountHostRegistry::class)->allHosts() as $host) {
        $this->getJson('http://'.$host.'/api/v1/me')->assertUnauthorized();
    }
});

it('does not require an account host for partner webhook routes', function (): void {
    // Webhook routes are registered under /api and therefore excluded from the SPA
    // fallback and from ResolveAccountHost. Assert structurally: no route outside the
    // browser shell carries the account-host middleware.
    // Routes that are BROWSER-ORIGINATED by definition and therefore legitimately host-resolved.
    // Phase UI-03 added the three authentication ones: ADR-019 binds a Magic Link to the exact
    // host it was issued for, and ADR-018 binds a context handoff to its exact target host — both
    // need the resolved host as an ANTI-SUBSTITUTION input. Resolving it still grants nothing
    // (ADR-017, proven by AccountHostDoesNotAuthorizeTest).
    //
    // The invariant this test protects is unchanged: no MACHINE route — health probe, partner
    // webhook, signed file route, queue or scheduler surface — may depend on account-host
    // resolution.
    $browserOriginated = [
        'spa.shell',
        'auth.switch.consume',
        'auth.magic-link.request',
        'auth.magic-link.verify',
        'auth.account-contexts.switch',
    ];

    $offenders = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (in_array($route->getName(), $browserOriginated, true)) {
            continue;
        }
        if (in_array(ResolveAccountHost::class, $route->gatherMiddleware(), true)) {
            $offenders[] = $route->uri();
        }
    }

    expect($offenders)->toBe([], 'Non-browser routes must not depend on account-host '
        .'resolution: '.implode(', ', $offenders));
});
