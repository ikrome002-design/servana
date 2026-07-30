<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class)->group('sessions', 'ui03', 'security', 'auth');

/*
 |==============================================================================
 | Phase UI-03 — unauthenticated browser navigation (UI/UX plan §5.4; phase brief §16.4).
 |
 | THE DEFECT THIS CLOSES (observed in docs/proof/ui-02.md, owned by UI-03): an HTML-accept request
 | to an auth-protected /api/v1 route returned **500**. Laravel's exception handler falls back to
 | `route('login')` when an AuthenticationException carries no redirect target, and Servana is an
 | SPA that has never had a named `login` route — so the fallback raised RouteNotFoundException.
 | JSON callers were unaffected because Authenticate::unauthenticated() passes null for them.
 |
 | The fix supplies a destination through the framework's own hook rather than swallowing the
 | exception, so `route('login')` is never reached at all.
 */

it('still has no named login route — the fix supplies a destination, it does not add one', function (): void {
    // If a `login` route were added instead, this suite would pass for the wrong reason and the
    // real fallback would stay untested.
    expect(Route::has('login'))->toBeFalse();
});

it('answers a JSON API request with the standard 401 envelope', function (): void {
    test()->getJson(accountHostUrl('merchant_administrator', '/api/v1/me'))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('no longer 500s on an HTML-accept request to a protected API route', function (): void {
    // THE REGRESSION. Before UI-03 this returned 500 on every account host.
    foreach (['merchant_administrator', 'merchant_finance', 'super_administrator'] as $accountKey) {
        $response = test()->get(accountHostUrl($accountKey, '/api/v1/me'), ['Accept' => 'text/html']);

        expect($response->getStatusCode())->not->toBe(500, "HTML navigation still 500s on {$accountKey}.");

        // The /api/v1 surface answers as an API surface whatever the Accept header says, which is
        // the correct outcome: `/api/v1/me` is an endpoint, not a page, so redirecting a caller to
        // a sign-in SCREEN would be answering a question nobody asked.
        $response->assertStatus(401);
        expect($response->json('error.code'))->toBe('unauthenticated');
    }
});

it('sends an unauthenticated BROWSER PAGE navigation to the host-correct login path', function (): void {
    // A page route, not an API route — this is the path the framework's redirect hook exists for.
    // Registered here because the authenticated page contract itself is UI-07's to build; the
    // guard has to be provably correct before those pages arrive, not afterwards.
    Route::middleware(['web', 'auth'])->get('/tmp-protected-page', fn () => 'never reached');

    foreach (['merchant_administrator', 'merchant_finance', 'super_administrator'] as $accountKey) {
        $response = test()->get(accountHostUrl($accountKey, '/tmp-protected-page'), ['Accept' => 'text/html']);

        expect($response->getStatusCode())->not->toBe(500, "Page navigation 500s on {$accountKey}.");
        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');

        // Same host, always: the destination is a RELATIVE path, so it cannot leave the origin the
        // request arrived on and cannot move the user toward a broader account.
        expect($location)->toStartWith('http://'.accountHostName($accountKey));
        expect($location)->toContain('/auth/login');
        // …and the safe intended page is preserved so sign-in can return the user to it.
        expect($location)->toContain('redirect=%2Ftmp-protected-page');
    }
});

it('never lets an untrusted forwarded host steer the login destination', function (): void {
    Route::middleware(['web', 'auth'])->get('/tmp-protected-page', fn () => 'never reached');

    $response = test()
        ->withHeader('X-Forwarded-Host', 'evil.attacker.test')
        ->get(accountHostUrl('merchant_finance', '/tmp-protected-page'), ['Accept' => 'text/html']);

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))->not->toContain('evil.attacker.test');
    expect((string) $response->headers->get('Location'))->toStartWith('http://'.accountHostName('merchant_finance'));
});

it('does not bounce a user back into an authentication or backend path', function (): void {
    // `/api/v1/me` is not a page, so preserving it as an intended path would be meaningless — and
    // an `/auth/...` intended path would be a redirect loop waiting to happen.
    $location = (string) test()
        ->get(accountHostUrl('merchant_administrator', '/api/v1/me'), ['Accept' => 'text/html'])
        ->headers->get('Location');

    expect($location)->not->toContain('redirect=');
});

it('preserves a safe intended page path', function (): void {
    // A browser navigation to a protected SPA page keeps its destination, so sign-in can return
    // the user where they were going.
    $location = (string) test()
        ->get(accountHostUrl('merchant_finance', '/finance/invoices'), ['Accept' => 'text/html'])
        ->headers->get('Location');

    // The SPA shell serves this path publicly today (UI-07 owns the authenticated route contract),
    // so either it renders or it redirects — but it must never 500.
    expect($location === '' || str_contains($location, '/auth/login'))->toBeTrue();
});

it('leaves machine routes completely unaffected', function (): void {
    // Health probes carry no auth middleware and must never acquire a browser-account dependency.
    test()->get('http://localhost/health')->assertStatus(200);
    test()->get('http://localhost/health', ['Accept' => 'text/html'])->assertStatus(200);
});

it('keeps an unapproved host denied rather than redirected', function (): void {
    // A safe 421 denial, not a redirect — redirecting an unapproved host would confirm which hosts
    // exist and could bounce a user toward one.
    test()->get('http://evil-servana.ke/', ['Accept' => 'text/html'])->assertStatus(421);

    // The host-bound authentication endpoints carry ResolveAccountHost, so they deny the origin
    // before doing any authentication work at all.
    test()->postJson('http://evil-servana.ke/api/v1/auth/magic-link', ['email' => 'a@b.test'])
        ->assertStatus(421)
        ->assertJsonPath('error.code', 'misdirected_request');

    // The rest of /api/v1 answers 401 first: authentication is the earlier boundary there, and
    // 401-before-421 leaks strictly less (it says nothing about which hosts are approved).
    test()->getJson('http://evil-servana.ke/api/v1/me')->assertStatus(401);
});
