<?php

declare(strict_types=1);

use App\Http\Hosts\AccountHostRegistry;
use Illuminate\Support\Str;

uses()->group('hosts', 'ui17', 'performance', 'security');

/*
 |==============================================================================
 | Phase UI-17 — the SPA shell's critical path and its CSP contract (UI/UX plan §6.5, §18.6, §22.1).
 |
 | UI17-PERF-003: the shell named only the entry chunk, so the entry's static imports were found a
 | round trip late. It now emits `modulepreload` for them, and on `/` for THIS account's landing
 | chunks and the two hero font faces — all derived from the real Vite manifest.
 |
 | The CSP pins the theme bootstrap by hash. A browser hashes the script exactly as SERVED, so this
 | hashes the Blade-rendered output (not the template source) against docker/nginx/default.conf.
 */

if (! function_exists('ui17Manifest')) {
    /** @return array<string, array{file: string, imports?: list<string>, css?: list<string>}> */
    function ui17Manifest(): array
    {
        /** @var array<string, array{file: string, imports?: list<string>, css?: list<string>}> */
        return json_decode((string) file_get_contents(public_path('spa/.vite/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    function ui17Links(string $html, string $rel): array
    {
        preg_match_all('#<link rel="'.preg_quote($rel, '#').'" href="([^"]+)"#', $html, $m);

        return $m[1];
    }

    /** @return list<string> the static-import closure of a manifest key, as served URLs */
    function ui17Closure(array $manifest, string $key): array
    {
        $files = [];
        $stack = [$key];
        $seen = [];
        while ($stack !== []) {
            $k = array_pop($stack);
            if (isset($seen[$k]) || ! isset($manifest[$k])) {
                continue;
            }
            $seen[$k] = true;
            $files[] = '/'.$manifest[$k]['file'];
            foreach ($manifest[$k]['imports'] ?? [] as $import) {
                $stack[] = $import;
            }
        }

        return $files;
    }
}

it('modulepreloads the entry chunk\'s static imports on every account host and path', function (): void {
    $manifest = ui17Manifest();
    $entry = '/'.$manifest['index.html']['file'];
    $expected = array_values(array_diff(ui17Closure($manifest, 'index.html'), [$entry]));
    expect($expected)->not->toBeEmpty();

    foreach (app(AccountHostRegistry::class)->allHosts() as $host) {
        foreach (['/', '/login', '/faq'] as $path) {
            $preloads = ui17Links((string) $this->get('http://'.$host.$path)->assertOk()->getContent(), 'modulepreload');
            foreach ($expected as $file) {
                expect($preloads)->toContain($file);
            }
            expect($preloads)->not->toContain($entry);
        }
    }
});

it('preloads exactly this account\'s landing chunks on / and nothing landing-specific elsewhere', function (): void {
    $manifest = ui17Manifest();
    $registry = app(AccountHostRegistry::class);

    $landingFile = static fn (string $contentKey): string => '/'.$manifest['src/content/generated/'.$contentKey.'/landing.generated.ts']['file'];
    $compositionFile = static fn (string $contentKey): string => '/'.$manifest['src/content/landing/accounts/'.Str::camel($contentKey).'.ts']['file'];
    $allContentKeys = array_map(static fn (string $key): string => $registry->get($key)->publicContentKey, $registry->accountKeys());

    foreach ($registry->accountKeys() as $accountKey) {
        $account = $registry->get($accountKey);
        $host = $registry->hostForAccount($accountKey, 'local');

        $root = ui17Links((string) $this->get('http://'.$host.'/')->assertOk()->getContent(), 'modulepreload');
        expect($root)->toContain($landingFile($account->publicContentKey))
            ->toContain($compositionFile($account->publicContentKey))
            ->toContain('/'.$manifest['src/pages/public/PublicLandingPage.vue']['file']);

        // No other account's copy or composition is ever named on this host.
        foreach (array_diff($allContentKeys, [$account->publicContentKey]) as $foreign) {
            expect($root)->not->toContain($landingFile($foreign))
                ->not->toContain($compositionFile($foreign));
        }

        $login = ui17Links((string) $this->get('http://'.$host.'/login')->assertOk()->getContent(), 'modulepreload');
        expect($login)->not->toContain($landingFile($account->publicContentKey))
            ->not->toContain('/'.$manifest['src/pages/public/PublicLandingPage.vue']['file']);
    }
});

it('preloads the two hero font faces with CORS only on the landing page', function (): void {
    $manifest = ui17Manifest();
    $fonts = [
        '/'.$manifest['../../node_modules/@fontsource/manrope/files/manrope-latin-800-normal.woff2']['file'],
        '/'.$manifest['../../node_modules/@fontsource/inter/files/inter-latin-400-normal.woff2']['file'],
    ];

    $root = (string) $this->get('http://servana.test/')->assertOk()->getContent();
    foreach ($fonts as $font) {
        expect($root)->toContain('<link rel="preload" href="'.$font.'" as="font" type="font/woff2" crossorigin />');
    }
    expect(substr_count($root, 'as="font"'))->toBe(2);

    foreach (['/login', '/faq', '/dashboard'] as $path) {
        expect((string) $this->get('http://servana.test'.$path)->getContent())->not->toContain('as="font"');
    }
});

it('serves the theme bootstrap with exactly the hash the edge CSP allows', function (): void {
    $html = (string) $this->get('http://audit.servana.test/')->assertOk()->getContent();

    preg_match_all('#<script>(.*?)</script>#s', $html, $scripts);
    expect($scripts[1])->toHaveCount(1);
    $served = 'sha256-'.base64_encode(hash('sha256', $scripts[1][0], true));

    $conf = (string) file_get_contents(base_path('docker/nginx/default.conf'));
    preg_match('/add_header Content-Security-Policy "([^"]+)" always;/', $conf, $csp);
    expect($csp[1] ?? '')->toContain("'".$served."'")
        ->not->toContain("'unsafe-inline'")
        ->not->toContain("'unsafe-eval'");
});
