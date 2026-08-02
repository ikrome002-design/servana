<?php

declare(strict_types=1);

uses()->group('docs', 'ui05', 'contracts', 'assets', 'images');

/*
 |==============================================================================
 | Phase UI-05 — responsive derivative contract (UI/UX plan §22.2).
 |
 | Derivatives are committed binaries, so the properties worth asserting are the ones a reviewer
 | cannot eyeball: that every recorded file exists and still decodes, that its pixels match what the
 | manifest claims, that nothing was UPSCALED (synthesising pixels the supplied artwork never had),
 | and that the originals came through untouched.
 */

it('generates AVIF and WebP candidates for every selected image, and nothing else', function (): void {
    $manifest = ui05Audit('derivative-manifest');
    $images = ui05ImageManifest();

    /** @var array<string, int> $byFormat */
    $byFormat = (array) $manifest['counts_by_format'];
    expect(array_keys($byFormat))->toBe(['avif', 'webp']);
    expect($byFormat['avif'])->toBe($byFormat['webp']);
    expect($manifest['total_derivatives'])->toBe($byFormat['avif'] + $byFormat['webp']);
    expect($images['total_derivatives'])->toBe($manifest['total_derivatives']);

    /** @var list<array<string, mixed>> $derivatives */
    $derivatives = $manifest['derivatives'];
    expect($derivatives)->toHaveCount((int) $manifest['total_derivatives']);
});

it('proves every derivative exists, decodes and matches its recorded bytes', function (): void {
    $manifest = ui05Audit('derivative-manifest');

    /** @var list<array<string, mixed>> $derivatives */
    $derivatives = $manifest['derivatives'];
    foreach ($derivatives as $derivative) {
        $path = base_path((string) $derivative['path']);
        expect(file_exists($path))->toBeTrue("Missing derivative: {$derivative['path']}");

        $bytes = (string) file_get_contents($path);
        expect(hash('sha256', $bytes))->toBe($derivative['sha256'], "{$derivative['path']} is stale.");
        expect(strlen($bytes))->toBe($derivative['bytes']);

        // Container signatures, checked directly: PHP's getimagesize does not read AVIF, and a
        // mislabelled extension is exactly the MIME defect this contract exists to catch.
        if ($derivative['format'] === 'avif') {
            expect(substr($bytes, 4, 8))->toBe('ftypavif', "{$derivative['path']} is not an AVIF file.");
            expect($derivative['mime_type'])->toBe('image/avif');
            expect($derivative['path'])->toEndWith('.avif');
        } else {
            expect(substr($bytes, 0, 4))->toBe('RIFF', "{$derivative['path']} is not a RIFF container.");
            expect(substr($bytes, 8, 4))->toBe('WEBP', "{$derivative['path']} is not a WebP file.");
            expect($derivative['mime_type'])->toBe('image/webp');
            expect($derivative['path'])->toEndWith('.webp');
        }
    }
});

it('never upscales a supplied image', function (): void {
    $manifest = ui05Audit('derivative-manifest');
    expect($manifest['no_upscale'])->toContain('dropped rather than synthesised');

    $images = ui05ImageManifest();
    /** @var list<array<string, mixed>> $sourceImages */
    $sourceImages = $images['images'];

    foreach ($sourceImages as $image) {
        /** @var list<array<string, mixed>> $derivatives */
        $derivatives = $image['derivatives'];
        expect($derivatives)->not->toBeEmpty();

        foreach ($derivatives as $derivative) {
            expect($derivative['width'])->toBeLessThanOrEqual($image['source_width']);
            expect($derivative['height'])->toBeLessThanOrEqual($image['source_height']);
            // The candidate must also stay in proportion — a stretched derivative would be a
            // material alteration of supplied art.
            expect(abs(($derivative['width'] / $derivative['height']) - (float) $image['aspect_ratio']))
                ->toBeLessThan(0.01);
        }
    }
});

it('writes every derivative inside the approved generated directory, with a safe name', function (): void {
    $manifest = ui05Audit('derivative-manifest');

    /** @var list<array<string, mixed>> $derivatives */
    $derivatives = $manifest['derivatives'];
    $paths = [];

    foreach ($derivatives as $derivative) {
        $path = (string) $derivative['path'];
        $account = (string) $derivative['account_key'];

        expect($path)->toStartWith("public/assets/landing_page_images/generated/{$account}/");
        expect($path)->not->toContain('..');
        expect($derivative['public_path'])->toBe('/'.substr($path, strlen('public/')));
        // Lowercase, no spaces: a generated URL must not be case- or encoding-ambiguous.
        expect(preg_match('#^[a-z0-9/_.-]+$#', $path))->toBe(1, "Unsafe derivative path: {$path}");

        $paths[] = $path;
    }

    expect(array_unique($paths))->toHaveCount(count($paths), 'Two derivatives collide on one path.');
    expect(array_unique(array_map('strtolower', $paths)))->toHaveCount(count($paths));
});

it('leaves no orphan under the generated image tree', function (): void {
    $manifest = ui05Audit('derivative-manifest');

    /** @var list<array<string, mixed>> $derivatives */
    $derivatives = $manifest['derivatives'];
    $expected = array_map(static fn (array $derivative): string => (string) $derivative['path'], $derivatives);
    sort($expected);

    $onDisk = [];
    $root = base_path('public/assets/landing_page_images/generated');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $onDisk[] = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file->getPathname());
        }
    }
    sort($onDisk);

    expect($onDisk)->toBe($expected, 'The generated image tree and the derivative manifest disagree.');
});

it('leaves all sixty-one supplied originals byte-identical', function (): void {
    $manifest = ui05Audit('derivative-manifest');
    expect($manifest['originals_unmodified'])->toContain('read only');

    // The supplied artwork must still be exactly what the UI-00 inventory hashed before this phase.
    /** @var array<string, mixed> $inventory */
    $inventory = json_decode(
        (string) file_get_contents(base_path('docs/frontend/source-inventory/landing-images.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    /** @var list<array<string, mixed>> $images */
    $images = $inventory['images'];
    expect($images)->toHaveCount(61);
    expect($inventory['total_images'])->toBe(61);

    foreach ($images as $image) {
        $path = base_path((string) $image['path']);
        expect(file_exists($path))->toBeTrue("A supplied image disappeared: {$image['path']}");
        expect(hash('sha256', (string) file_get_contents($path)))->toBe(
            $image['sha256'],
            "A supplied original was modified: {$image['path']}",
        );
    }

    // The generated tree must not have been counted as supplied artwork.
    foreach ($images as $image) {
        expect((string) $image['path'])->not->toContain('/generated/');
    }
});

it('pins every encoder option that affects the output bytes', function (): void {
    $images = ui05ImageManifest();

    /** @var array<string, mixed> $encoders */
    $encoders = $images['encoder_options'];
    expect(array_keys($encoders))->toBe(['avif', 'webp']);

    /** @var array<string, mixed> $avif */
    $avif = $encoders['avif'];
    /** @var array<string, mixed> $avifOptions */
    $avifOptions = $avif['options'];
    foreach (['quality', 'effort', 'chromaSubsampling', 'lossless'] as $option) {
        expect($avifOptions)->toHaveKey($option);
    }

    /** @var array<string, mixed> $resize */
    $resize = $images['resize_options'];
    expect($resize['kernel'])->toBe('lanczos3');
    expect($resize['withoutEnlargement'])->toBeTrue();
    expect($resize['fit'])->toBe('inside');

    $manifest = ui05Audit('derivative-manifest');
    expect($manifest['determinism'])->toContain('pinned');
});

it('serves the untouched original as the fallback, with the reason recorded', function (): void {
    $images = ui05ImageManifest();

    /** @var array<string, mixed> $policy */
    $policy = $images['selection_policy'];
    expect($policy['fallback'])->toBe('original');
    expect($policy['formats'])->toBe(['avif', 'webp']);
    expect($policy['responsive_widths'])->toBe([640, 1024, 1440]);
    expect($policy['fallback_note'])->toBeString()->not->toBe('');
});
