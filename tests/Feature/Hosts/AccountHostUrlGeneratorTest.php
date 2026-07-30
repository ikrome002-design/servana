<?php

declare(strict_types=1);

use App\Http\Hosts\AccountHostUrlGenerator;
use Illuminate\Http\Request;

uses()->group('hosts', 'ui02', 'security');

/*
 |==============================================================================
 | Phase UI-02 — allowlist-backed URL generation (ADR-016; UI/UX plan §4.4).
 |
 | Every absolute account URL is built from the REGISTRY, never from the incoming request's
 | Host header. That is what stops a poisoned host being reflected into an outbound link —
 | the classic password-reset-poisoning shape. It matters here because UI-03 will build
 | Magic Link URLs on top of this service (ADR-019); UI-02 provides the foundation only and
 | binds no Magic Link.
 */

function ui02Urls(): AccountHostUrlGenerator
{
    return app(AccountHostUrlGenerator::class);
}

it('builds absolute URLs from the allowlist for each environment', function (): void {
    expect(ui02Urls()->to('merchant_finance', '/dashboard', 'production'))
        ->toBe('https://finance.servana.ke/dashboard');

    expect(ui02Urls()->to('super_administrator', '/dashboard', 'staging'))
        ->toBe('https://citrus.servana.'.config('account_hosts.domains.staging_suffix').'/dashboard');

    // Local development is published on a container port, taken from configuration.
    expect(ui02Urls()->to('merchant_administrator', '/', 'local'))
        ->toBe('http://servana.test:'.config('account_hosts.url.local_port').'/');
});

it('builds each account dashboard from its configured default route', function (): void {
    foreach (array_keys((array) config('account_hosts.accounts')) as $accountKey) {
        expect(ui02Urls()->dashboard($accountKey, 'production'))->toEndWith('/dashboard');
    }
});

it('never derives the target host from the incoming request', function (): void {
    // A poisoned Host must not appear anywhere in a generated URL.
    $request = Request::create('/', 'GET');
    $request->headers->set('Host', 'attacker.test');
    app()->instance('request', $request);

    $url = ui02Urls()->to('merchant_finance', '/dashboard', 'production');

    expect($url)->toBe('https://finance.servana.ke/dashboard');
    expect($url)->not->toContain('attacker.test');
});

it('rejects an unknown account', function (): void {
    expect(fn () => ui02Urls()->to('not_an_account', '/'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects unsafe redirect paths', function (): void {
    foreach ([
        '//evil.test',
        '/\\evil.test',
        'https://evil.test',
        'evil.test',
        '',
        '/path\\with\\backslash',
        "/path\r\nX-Injected: 1",
        '//evil.test/legit-looking',
    ] as $path) {
        expect(ui02Urls()->safeRelativePath($path))
            ->toBeNull('must reject unsafe path: '.json_encode($path));
    }

    expect(fn () => ui02Urls()->to('merchant_finance', '//evil.test'))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts safe relative paths, preserving query and fragment', function (): void {
    expect(ui02Urls()->safeRelativePath('/dashboard'))->toBe('/dashboard');
    expect(ui02Urls()->safeRelativePath('/invoices?page=2'))->toBe('/invoices?page=2');
    expect(ui02Urls()->safeRelativePath('/reports#summary'))->toBe('/reports#summary');
});

it('omits the development port for production and staging', function (): void {
    // Only the scheme's own colon may remain — a published container port must never leak
    // into a production or staging URL.
    foreach (['production', 'staging'] as $environment) {
        $url = ui02Urls()->to('merchant_audit', '/dashboard', $environment);
        $withoutScheme = preg_replace('#^https?://#', '', $url) ?? $url;

        expect($withoutScheme)->not->toContain(':', "{$environment} URL carries a port: {$url}");
    }

    expect(ui02Urls()->to('merchant_audit', '/dashboard', 'production'))
        ->toBe('https://audit.servana.ke/dashboard');
});
