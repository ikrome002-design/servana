<?php

declare(strict_types=1);

use App\Http\Hosts\AccountHostRegistry;
use Illuminate\Support\Facades\Route;

uses()->group('hosts', 'ui02', 'contracts');

/*
 |==============================================================================
 | Phase UI-02 — the served SPA shell (UI01-PROV-001, UI01-PROV-002, UI01-ASSET-005).
 |
 | UI-01 found the deployed root returning Laravel's stock scaffold (`<title>Laravel</title>`)
 | and the only SPA mount, `/spa/`, loading an empty `#app` because its chunks 404'd. These
 | tests pin the corrected contract at the Laravel layer; the Nginx and production-image
 | halves are proven by the host smoke matrix (scripts/ui02-host-smoke.mjs).
 */

it('serves the Servana shell — never the Laravel scaffold — on every account host', function (): void {
    foreach (app(AccountHostRegistry::class)->allHosts() as $host) {
        $response = $this->get('http://'.$host.'/');

        $response->assertOk();
        $response->assertSee('Servana by Citrus', escape: false);
        $response->assertDontSee('<title>Laravel</title>', escape: false);
        // The stock scaffold pulled images from laravel.com; nothing may reintroduce it.
        $response->assertDontSee('laravel.com/assets', escape: false);
    }
});

it('marks the shell with the account key the host resolved to', function (): void {
    $registry = app(AccountHostRegistry::class);

    foreach ($registry->accountKeys() as $accountKey) {
        $host = $registry->hostForAccount($accountKey, 'local');

        $this->get('http://'.$host.'/')
            ->assertOk()
            ->assertSee('data-account-key="'.$accountKey.'"', escape: false);
    }
});

it('embeds a server-resolved account context that leaks nothing', function (): void {
    $response = $this->get('http://finance.servana.test/');
    $response->assertOk();

    $content = $response->getContent();
    expect($content)->toBeString();

    preg_match('#<script id="servana-account-context" type="application/json">(.*?)</script>#s', (string) $content, $m);
    expect(isset($m[1]))->toBeTrue('the shell embedded no account context');

    /** @var array<string, mixed> $context */
    $context = json_decode(html_entity_decode($m[1]), true, flags: JSON_THROW_ON_ERROR);

    expect($context['account_key'])->toBe('merchant_finance');
    expect($context['navigation_placement'])->toBe('sidebar');

    // The context is presentation metadata. Nothing identity-, tenant- or credential-shaped
    // may appear in it — it is rendered into an anonymous public page.
    foreach ([
        'user', 'user_id', 'email', 'merchant', 'merchant_id', 'tenant', 'branch_id',
        'permission', 'permissions', 'role_id', 'token', 'csrf', 'session', 'secret',
    ] as $forbidden) {
        expect($context)->not->toHaveKey($forbidden);
    }
});

it('gives the Super Administrator header navigation and everyone else sidebar', function (): void {
    $registry = app(AccountHostRegistry::class);

    foreach ($registry->accountKeys() as $accountKey) {
        $expected = $accountKey === 'super_administrator' ? 'header' : 'sidebar';

        expect($registry->get($accountKey)->navigationPlacement)->toBe($expected, $accountKey);
    }
});

it('names the fingerprinted Vite entry from the real manifest', function (): void {
    $manifestPath = public_path('spa/.vite/manifest.json');

    if (! is_file($manifestPath)) {
        $this->markTestSkipped('SPA manifest absent — run `npm run build`.');
    }

    /** @var array<string, array{file: string, css?: list<string>}> $manifest */
    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $entry = $manifest['index.html'];

    $response = $this->get('http://servana.test/');

    $response->assertOk();
    $response->assertSee('src="/'.$entry['file'].'"', escape: false);
    // Chunks live under /spa-assets/, never /assets/ — that collision with Laravel's own
    // public tree is exactly what made every chunk 404 (UI01-PROV-002).
    expect($entry['file'])->toStartWith('spa-assets/');

    foreach ($entry['css'] ?? [] as $css) {
        $response->assertSee('href="/'.$css.'"', escape: false);
        expect($css)->toStartWith('spa-assets/');
    }
});

it('references approved brand assets with canonical lowercase names', function (): void {
    $response = $this->get('http://servana.test/');

    foreach ([
        '/assets/brand/favicon.ico',
        '/assets/brand/favicon-32x32.png',
        '/assets/brand/favicon-16x16.png',
        '/assets/brand/apple-touch-icon.png',
    ] as $asset) {
        $response->assertSee($asset, escape: false);
    }

    // Logo.svg was deleted under product-owner authority and must never come back.
    $response->assertDontSee('Logo.svg', escape: false);
    expect(file_exists(public_path('assets/brand/Logo.svg')))->toBeFalse();
});

it('revalidates the shell so it can never pin a previous deployment', function (): void {
    $response = $this->get('http://servana.test/');

    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
});

it('denies an unapproved host with a safe, non-enumerating response', function (): void {
    $response = $this->get('http://attacker.test/');

    $response->assertStatus(421);
    // It must not name the approved hosts, redirect toward one, or imply an account.
    $response->assertDontSee('servana.ke', escape: false);
    $response->assertDontSee('servana.test', escape: false);
    $response->assertDontSee('data-account-key', escape: false);
    expect($response->headers->get('Location'))->toBeNull();
});

it('keeps backend routes out of the SPA fallback', function (): void {
    // The fallback is a negative lookahead, not a catch-all: a backend prefix must never
    // resolve to the shell regardless of route-registration order.
    $shellRoute = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->getName() === 'spa.shell');

    expect($shellRoute)->not->toBeNull();

    // It is a FALLBACK route, so it can never shadow a route registered later.
    expect($shellRoute->isFallback)->toBeTrue('the SPA shell must be a fallback route');

    $pattern = $shellRoute->wheres['fallbackPlaceholder'] ?? '';
    foreach (['api', 'health', 'up', 'sanctum', 'storage', 'spa-assets', 'assets', 'build'] as $prefix) {
        expect((bool) preg_match('#'.$pattern.'#', $prefix))
            ->toBeFalse("the SPA fallback matched backend prefix '{$prefix}'");
        expect((bool) preg_match('#'.$pattern.'#', $prefix.'/v1/me'))
            ->toBeFalse("the SPA fallback matched backend path '{$prefix}/v1/me'");
    }

    // ...while genuine browser routes still reach the shell.
    foreach (['dashboard', 'get-started', 'finance/invoices', 'legal/merchant_audit/privacy_policy'] as $browserPath) {
        expect((bool) preg_match('#'.$pattern.'#', $browserPath))
            ->toBeTrue("browser path '{$browserPath}' did not reach the shell");
    }
});

it('keeps the two theme bootstraps byte-identical', function (): void {
    // The Blade shell and the standalone SPA index.html both set the theme class before first
    // paint. They must not drift: a difference would mean one origin flashes and the other does
    // not, or worse, that one origin still honours the operating-system colour scheme.
    //
    // Phase UI-04 closed UI01-THEME-001 by rewriting this script in BOTH shells. The extraction
    // anchor moved with it (`Servana theme bootstrap`), and the contract itself is unchanged:
    // one script, two shells, byte-identical.
    $extract = static function (string $path): string {
        $contents = (string) file_get_contents($path);
        preg_match('#// Servana theme bootstrap.*?\n\s*</script>#s', $contents, $m);

        return preg_replace('/\s+/', ' ', $m[0] ?? '') ?? '';
    };

    $shell = $extract(resource_path('views/spa.blade.php'));
    $index = $extract(resource_path('spa/index.html'));

    expect($shell)->not->toBe('', 'no theme bootstrap found in the Blade shell');
    expect($shell)->toBe($index, 'the shell and index.html theme bootstraps have drifted');
});

it('never lets the operating system select the theme in either shell (UI01-THEME-001)', function (): void {
    // ADR-021 rule 2 and CLAUDE.md guardrail 15. The audited defect was precisely a
    // `prefers-color-scheme` read in the pre-hydration script, so this is asserted directly on
    // both shells rather than inferred from the byte-identity test above.
    foreach (['views/spa.blade.php', 'spa/index.html'] as $shell) {
        $contents = (string) file_get_contents(resource_path($shell));

        expect($contents)->not->toContain('prefers-color-scheme');
        expect($contents)->not->toContain('matchMedia');
        // The only value that may add the dark class is an explicit Servana preference.
        expect($contents)->toContain("svTheme === 'dark'");
        expect($contents)->toContain("localStorage.getItem('servana.theme')");
        expect($contents)->toContain("getAttribute('data-sv-theme')");
    }
});
