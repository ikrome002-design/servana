<?php

declare(strict_types=1);

uses()->group('docs', 'ui05', 'contracts', 'assets');

/*
 |==============================================================================
 | Phase UI-05 — broken-asset contract.
 |
 | "No broken asset request" is the phase's acceptance criterion, and it has two halves that are
 | easy to prove separately and easy to forget together: every path the pipeline PUBLISHES must
 | resolve, and every path it WITHDRAWS must not. A matrix that only listed the first would pass
 | happily while eleven quarantined files were still being served.
 |
 | The matrix here is the offline half. The production smoke (`scripts/ui05-production-smoke.mjs`)
 | drives the same matrix over HTTP against the built nginx image.
 */

it('resolves every asset the matrix says must serve', function (): void {
    $matrix = ui05Audit('broken-asset-matrix');

    /** @var list<array<string, mixed>> $mustServe */
    $mustServe = $matrix['must_serve'];
    expect($mustServe)->not->toBeEmpty();

    $extensionToMime = [
        'png' => 'image/png',
        'avif' => 'image/avif',
        'webp' => 'image/webp',
        'json' => 'application/json',
    ];

    foreach ($mustServe as $entry) {
        $relative = (string) $entry['path'];
        $absolute = base_path($relative);

        expect(file_exists($absolute))->toBeTrue("Broken asset: {$relative}");
        expect($entry['expect_status'])->toBe(200);

        // The file must live under the public root, or nginx could never serve it.
        expect($relative)->toStartWith('public/');
        expect($entry['public_path'])->toBe('/'.substr($relative, strlen('public/')));

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        expect(array_key_exists($extension, $extensionToMime))->toBeTrue("Unexpected published extension: {$extension}");
        expect($entry['mime_type'])->toBe($extensionToMime[$extension], "MIME/extension mismatch for {$relative}.");

        expect(filesize($absolute))->toBeGreaterThan(0);
    }
});

it('withdraws every asset the matrix says must not serve', function (): void {
    $matrix = ui05Audit('broken-asset-matrix');

    /** @var list<array<string, mixed>> $mustNotServe */
    $mustNotServe = $matrix['must_not_serve'];

    // Logo.svg, the wrong-case logo, and the eleven quarantined working files.
    expect($mustNotServe)->toHaveCount(13);

    foreach ($mustNotServe as $entry) {
        $publicPath = (string) $entry['public_path'];
        expect($entry['expect_status'])->toBe(404);
        expect($entry['reason'])->toBeString()->not->toBe('');
        expect($publicPath)->toStartWith('/assets/');

        $relative = 'public'.$publicPath;

        // `logo.png` is a case variant of a file that legitimately exists; on a case-insensitive
        // filesystem `file_exists` answers about `Logo.png`, so it is checked by exact listing.
        if ($publicPath === '/assets/brand/logo.png') {
            $names = array_map('basename', glob(base_path('public/assets/brand/*.png')) ?: []);
            expect($names)->not->toContain('logo.png');

            continue;
        }

        expect(file_exists(base_path($relative)))->toBeFalse("Still present under the public root: {$relative}");
    }
});

it('covers every selected image, every derivative, the logo and the manifest', function (): void {
    $matrix = ui05Audit('broken-asset-matrix');
    $images = ui05ImageManifest();
    $derivatives = ui05Audit('derivative-manifest');

    /** @var list<array<string, mixed>> $mustServe */
    $mustServe = $matrix['must_serve'];
    $covered = array_map(static fn (array $entry): string => (string) $entry['path'], $mustServe);

    expect($covered)->toContain('public/assets/brand/Logo.png');
    expect($covered)->toContain('public/assets/landing_page_images/manifest.json');

    /** @var list<array<string, mixed>> $selected */
    $selected = $images['images'];
    foreach ($selected as $image) {
        expect($covered)->toContain((string) $image['source_path']);
    }

    /** @var list<array<string, mixed>> $rows */
    $rows = $derivatives['derivatives'];
    foreach ($rows as $row) {
        expect($covered)->toContain((string) $row['path']);
    }

    expect(array_unique($covered))->toHaveCount(count($covered));
    expect($covered)->toHaveCount(2 + count($selected) + count($rows));
});

it('registers no service worker anywhere in the published tree', function (): void {
    foreach (['public/sw.js', 'public/service-worker.js', 'public/spa/sw.js'] as $path) {
        expect(file_exists(base_path($path)))->toBeFalse("A service worker was published at {$path}.");
    }

    $shell = (string) file_get_contents(base_path('resources/views/spa.blade.php'));
    expect($shell)->not->toContain('serviceWorker');
});
