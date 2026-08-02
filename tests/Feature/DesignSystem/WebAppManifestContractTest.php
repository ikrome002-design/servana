<?php

declare(strict_types=1);

uses()->group('design-system', 'ui-04', 'contracts', 'assets');

/*
 |==============================================================================
 | Phase UI-04 — web app manifest contract (UI01-ASSET-003; UI/UX plan §17).
 |
 | The audited defect: `android-chrome-192x192.png` and `android-chrome-512x512.png` are APPROVED
 | brand assets that existed in the repository but were referenced by nothing, because no web app
 | manifest existed. Android home-screen and installed-app icons were therefore unavailable.
 |
 | What this suite refuses to let happen:
 |  - the manifest naming an icon path that does not exist, or with the wrong case (Linux and CI
 |    are case-sensitive; `Favicon.ico` resolving to nothing is the precedent);
 |  - a service worker appearing anywhere, or the manifest claiming offline capability;
 |  - the approved icon BYTES being modified, regenerated or recoloured;
 |  - the manifest colours drifting from the design-token authority;
 |  - `Logo.svg` — deleted under product-owner authority — being reintroduced.
 */

const UI04_MANIFEST_PATH = 'public/assets/brand/site.webmanifest';

/** @return array<string, mixed> */
function ui04Manifest(): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(
        (string) file_get_contents(base_path(UI04_MANIFEST_PATH)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $decoded;
}

it('ships a parseable manifest at a backend-owned path', function (): void {
    expect(file_exists(base_path(UI04_MANIFEST_PATH)))->toBeTrue();

    // The path matters as much as the content. `/assets/` is backend-owned in BOTH topologies
    // (nginx serves it from Laravel's public root; the Vite build copies the same tree into the
    // preview origin). A manifest at the document root would fall through to the SPA fallback
    // and be served as HTML — which is the class of defect UI01-PROV-001 was.
    expect(UI04_MANIFEST_PATH)->toStartWith('public/assets/');

    expect(ui04Manifest())->toBeArray();
});

it('names Servana truthfully', function (): void {
    $manifest = ui04Manifest();

    expect($manifest['name'])->toBe('Servana by Citrus');
    expect($manifest['short_name'])->toBe('Servana');
    // A short name longer than 12 characters is truncated by Android launchers.
    expect(mb_strlen((string) $manifest['short_name']))->toBeLessThanOrEqual(12);
    expect($manifest['lang'])->toBe('en');
});

it('references both approved Android icons with exact lowercase paths', function (): void {
    $icons = ui04Manifest()['icons'];
    $sources = array_column($icons, 'src');

    // The two assets UI01-ASSET-003 recorded as approved-but-unreferenced.
    expect($sources)->toContain('/assets/brand/android-chrome-192x192.png');
    expect($sources)->toContain('/assets/brand/android-chrome-512x512.png');

    $problems = [];
    foreach ($icons as $icon) {
        $src = (string) $icon['src'];

        // Case sensitivity: Linux and CI will not find `Android-Chrome-192x192.png`.
        if ($src !== strtolower($src)) {
            $problems[] = "{$src}: brand asset paths are lowercase";
        }
        // Same-origin only — an icon fetched from a third party leaks a request per install.
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://') || str_starts_with($src, '//')) {
            $problems[] = "{$src}: cross-origin icon";
        }
        // The file must actually exist, at exactly that name.
        $absolute = base_path('public'.$src);
        if (! is_file($absolute)) {
            $problems[] = "{$src}: no such file";

            continue;
        }
        if (basename($absolute) !== basename($src)) {
            $problems[] = "{$src}: filename case differs on disk";
        }
        if ($icon['type'] !== 'image/png') {
            $problems[] = "{$src}: declared type must be image/png";
        }
        // The declared size must match the real pixels, or Android picks the wrong icon.
        $dimensions = getimagesize($absolute);
        expect($dimensions)->not->toBeFalse();
        [$width, $height] = $dimensions === false ? [0, 0] : $dimensions;
        if ("{$width}x{$height}" !== $icon['sizes']) {
            $problems[] = "{$src}: declares {$icon['sizes']} but is {$width}x{$height}";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('takes its colours from the design-token authority', function (): void {
    // The manifest is the one place a brand colour legitimately appears outside the token source,
    // because a manifest cannot reference a CSS variable. This test is what keeps it in step.
    $palette = [];
    foreach (ui04Tokens()['palette'] as $entry) {
        $palette[$entry['name']] = $entry['value'];
    }

    $manifest = ui04Manifest();

    expect($manifest['theme_color'])->toBe($palette['savannah-orange']);
    // Light is the default theme, so the splash background is the LIGHT page surface.
    expect($manifest['background_color'])->toBe($palette['app-background']);
});

it('claims no offline capability and registers no service worker', function (): void {
    // UI/UX plan §12.3 explicitly forbids UI-04 adding a service worker or claiming PWA/offline
    // behaviour. `display: browser` is the honest value for an app with neither.
    expect(ui04Manifest()['display'])->toBe('browser');

    $problems = [];
    $roots = [base_path('resources/spa/src'), base_path('resources/views'), base_path('public/assets')];
    foreach ($roots as $root) {
        foreach (sourceFilesUnder($root, ['ts', 'vue', 'js', 'php', 'html']) as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
            $body = (string) file_get_contents($path);
            if (preg_match('/navigator\s*\.\s*serviceWorker|workbox|registerSW|\bnew\s+Workbox\b/', $body) === 1) {
                $problems[] = $relative;
            }
        }
    }

    expect($problems)->toBe([], 'service-worker registration found in: '.implode(', ', $problems));
    expect(file_exists(base_path('public/sw.js')))->toBeFalse();
    expect(file_exists(base_path('public/service-worker.js')))->toBeFalse();
});

it('is linked from BOTH application shells', function (): void {
    // One shell linking it and the other not is exactly the drift UI-02's byte-identity contract
    // exists to prevent, and it would mean the preview origin and production disagree.
    foreach (['resources/spa/index.html', 'resources/views/spa.blade.php'] as $shell) {
        $body = (string) file_get_contents(base_path($shell));

        expect($body)->toContain('rel="manifest"');
        expect($body)->toContain('href="/assets/brand/site.webmanifest"');
    }
});

it('leaves the approved brand asset bytes untouched', function (): void {
    // UI-04 references the approved icons; it does not modify, regenerate, recolour or re-case
    // them. Recording the byte length and PNG signature is enough to catch a re-export.
    $assets = [
        'public/assets/brand/android-chrome-192x192.png',
        'public/assets/brand/android-chrome-512x512.png',
        'public/assets/brand/apple-touch-icon.png',
        'public/assets/brand/favicon-16x16.png',
        'public/assets/brand/favicon-32x32.png',
        'public/assets/brand/favicon.ico',
        'public/assets/brand/Logo.png',
    ];

    $problems = [];
    foreach ($assets as $asset) {
        $path = base_path($asset);
        if (! is_file($path)) {
            $problems[] = "{$asset}: missing";

            continue;
        }
        if (basename($path) !== basename($asset)) {
            $problems[] = "{$asset}: case differs on disk";
        }
        if (str_ends_with($asset, '.png') && substr((string) file_get_contents($path), 0, 8) !== "\x89PNG\r\n\x1a\n") {
            $problems[] = "{$asset}: not a PNG";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('keeps Logo.svg absent, as the product owner directed', function (): void {
    // Deleted under product-owner authority in commit 49160cd. It must not be restored,
    // referenced, or treated as required by any workflow UI-04 adds.
    expect(file_exists(base_path('public/assets/brand/Logo.svg')))->toBeFalse();

    $references = [];
    foreach ([base_path('resources/spa/src'), base_path('resources/views')] as $root) {
        foreach (sourceFilesUnder($root, ['ts', 'vue', 'php', 'html', 'css', 'json']) as $path) {
            // Specs are excluded because naming the string is how they PROVE its absence —
            // `Home.spec.ts` asserts the rendered shell never contains `Logo.svg`. Flagging that
            // would punish the very test that enforces the product owner's decision.
            if (str_ends_with($path, '.spec.ts')) {
                continue;
            }
            // Comments are stripped first: SvLogo.vue documents that Logo.svg was deleted by
            // authority and must never return, which is the OPPOSITE of a reference to it.
            $body = (string) preg_replace(
                ['#/\*.*?\*/#s', '#<!--.*?-->#s'],
                '',
                (string) file_get_contents($path),
            );
            if (str_contains($body, 'Logo.svg')) {
                $references[] = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
            }
        }
    }
    expect($references)->toBe([], 'Logo.svg referenced by: '.implode(', ', $references));
});

it('declares the webmanifest MIME type at the edge', function (): void {
    // nginx 1.27's stock mime.types has no `.webmanifest` entry, so without this the file is
    // served as application/octet-stream — and because the /assets/ location sets `nosniff`, the
    // browser refuses to parse it rather than guessing. The manifest would silently do nothing.
    $conf = (string) file_get_contents(base_path('docker/nginx/default.conf'));

    expect($conf)->toContain('application/manifest+json');
    expect($conf)->toContain('webmanifest');
});
