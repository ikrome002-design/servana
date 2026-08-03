<?php

declare(strict_types=1);

uses()->group('docs', 'ui05', 'contracts', 'assets', 'images');

/*
 |==============================================================================
 | Phase UI-05 — curated landing-image manifest (UI/UX plan §8.7).
 |
 | Sixty-one images were supplied; rendering all of them is explicitly forbidden. The manifest is
 | the curated subset, and it has to be truthful about three separate things: WHICH images (two to
 | four per role, each from that role's own directory), WHAT each one is (measured dimensions,
 | descriptive alternative text, the landing region it belongs to), and WHAT STANDING it has
 | (supplied ≠ selected ≠ release-approved).
 */

it('selects two to four images for every account, and no more', function (): void {
    $manifest = ui05ImageManifest();

    /** @var array<string, int> $counts */
    $counts = (array) $manifest['selected_by_account'];
    expect(array_keys($counts))->toHaveCount(8);

    foreach (UI05_ACCOUNTS as $account) {
        expect($counts)->toHaveKey($account);
        expect($counts[$account])->toBeGreaterThanOrEqual(2);
        expect($counts[$account])->toBeLessThanOrEqual(4);
    }

    expect($manifest['total_selected'])->toBe(array_sum($counts));
    // Sixty-one supplied, a curated subset selected. Never all of them.
    expect($manifest['total_selected'])->toBeLessThan(61);
});

it('never selects an image from another account\'s directory', function (): void {
    $manifest = ui05ImageManifest();

    /** @var list<array<string, mixed>> $images */
    $images = $manifest['images'];
    foreach ($images as $image) {
        $account = (string) $image['account_key'];

        expect($image['source_path'])->toBe("public/assets/landing_page_images/{$account}/{$image['source_file']}");
        expect($image['source_public_path'])->toBe("/assets/landing_page_images/{$account}/{$image['source_file']}");
        expect($image['source_file'])->not->toContain('/');
        expect($image['source_file'])->not->toContain('..');

        foreach (UI05_ACCOUNTS as $other) {
            if ($other !== $account) {
                expect((string) $image['source_path'])->not->toContain("/{$other}/");
            }
        }
    }
});

it('measures every selected image from the file rather than declaring it', function (): void {
    $manifest = ui05ImageManifest();

    /** @var list<array<string, mixed>> $images */
    $images = $manifest['images'];
    foreach ($images as $image) {
        $path = base_path((string) $image['source_path']);
        expect(file_exists($path))->toBeTrue("Missing selected image: {$image['source_path']}");

        $bytes = (string) file_get_contents($path);
        expect(hash('sha256', $bytes))->toBe($image['source_sha256']);
        expect(strlen($bytes))->toBe($image['source_bytes']);

        $size = getimagesize($path);
        expect($size)->not->toBeFalse("{$image['source_path']} does not decode as an image.");
        expect($size[0])->toBe($image['source_width']);
        expect($size[1])->toBe($image['source_height']);
        expect($size[0])->toBe($image['intrinsic_width']);
        expect($size[1])->toBe($image['intrinsic_height']);
        expect($size['mime'])->toBe($image['source_mime_type']);

        expect(round($size[0] / $size[1], 4))->toBe($image['aspect_ratio']);
    }
});

it('maps each image to exactly one landing region its own content supplies', function (): void {
    $manifest = ui05ImageManifest();
    $parity = ui05Audit('content-parity');

    /** @var array<string, array<string, string>> $presence */
    $presence = $parity['landing_region_presence'];

    /** @var list<array<string, mixed>> $images */
    $images = $manifest['images'];
    $pairs = [];
    $paths = [];

    foreach ($images as $image) {
        $account = (string) $image['account_key'];
        $region = (string) $image['landing_section'];

        expect($presence[$account][$region] ?? null)->toBe(
            'present_in_source',
            "{$account} maps an image to {$region}, which its landing document does not supply.",
        );

        $pairs[] = "{$account}:{$region}";
        $paths[] = (string) $image['source_path'];
    }

    expect(array_unique($pairs))->toHaveCount(count($pairs), 'Two images share one account/region slot.');
    expect(array_unique($paths))->toHaveCount(count($paths), 'One image is selected twice.');

    // Case-only collisions would resolve differently on Linux and on Windows.
    $lowered = array_map('strtolower', $paths);
    expect(array_unique($lowered))->toHaveCount(count($paths));
});

it('describes every non-decorative image without naming the file', function (): void {
    $manifest = ui05ImageManifest();

    /** @var list<array<string, mixed>> $images */
    $images = $manifest['images'];
    foreach ($images as $image) {
        $alt = (string) $image['alternative_text'];
        $file = (string) $image['source_file'];

        if ($image['decorative'] === true) {
            expect($alt)->toBe('', 'A decorative image must carry empty alternative text.');

            continue;
        }

        expect(strlen($alt))->toBeGreaterThan(20, "{$image['source_path']} has no descriptive alternative text.");
        expect(strtolower($alt))->not->toContain(strtolower($file));
        expect(strtolower($alt))->not->toContain('.png');
        expect(strtolower($alt))->not->toContain('image of');
        expect(strtolower($alt))->not->toContain('picture of');
    }
});

it('records the responsive contract every image needs to render', function (): void {
    $manifest = ui05ImageManifest();

    /** @var list<array<string, mixed>> $images */
    $images = $manifest['images'];
    foreach ($images as $image) {
        foreach ([
            'account_key', 'source_file', 'source_public_path', 'source_sha256', 'source_width',
            'source_height', 'source_format', 'landing_section', 'alternative_text', 'decorative',
            'intrinsic_width', 'intrinsic_height', 'aspect_ratio', 'focal_x', 'focal_y',
            'mobile_crop', 'tablet_crop', 'desktop_crop', 'loading_strategy', 'fetch_priority',
            'sizes', 'derivatives', 'source_approval', 'pipeline_status', 'release_status',
        ] as $field) {
            expect(array_key_exists($field, $image))->toBeTrue("{$image['source_path']} is missing {$field}.");
        }

        expect($image['focal_x'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(1);
        expect($image['focal_y'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(1);
        expect($image['sizes'])->toBeString()->not->toBe('');

        // Crops preserve the whole frame, so no subject can be cropped out of shot at any breakpoint.
        foreach (['mobile_crop', 'tablet_crop', 'desktop_crop'] as $crop) {
            /** @var array<string, mixed> $rectangle */
            $rectangle = $image[$crop];
            expect($rectangle['strategy'])->toBe('preserve_source_frame');
            expect($rectangle['x'])->toBe(0);
            expect($rectangle['y'])->toBe(0);
            expect($rectangle['width'])->toBe($image['source_width']);
            expect($rectangle['height'])->toBe($image['source_height']);
        }
    }
});

it('gives each account one eager high-priority hero and lazy-loads everything else', function (): void {
    $manifest = ui05ImageManifest();

    /** @var list<array<string, mixed>> $images */
    $images = $manifest['images'];
    $heroes = [];

    foreach ($images as $image) {
        if ($image['landing_section'] === 'hero') {
            $heroes[] = (string) $image['account_key'];
            expect($image['loading_strategy'])->toBe('eager');
            expect($image['fetch_priority'])->toBe('high');
        } else {
            expect($image['loading_strategy'])->toBe('lazy');
            expect($image['fetch_priority'])->toBe('auto');
        }
    }

    sort($heroes);
    $expected = UI05_ACCOUNTS;
    sort($expected);
    expect($heroes)->toBe($expected);
});

it('claims supplied and selected standing, never release approval', function (): void {
    $manifest = ui05ImageManifest();

    /** @var list<array<string, mixed>> $images */
    $images = $manifest['images'];
    foreach ($images as $image) {
        expect($image['source_approval'])->toBe('product_owner_supplied');
        expect($image['pipeline_status'])->toBe('selected_for_ui06');
        expect($image['release_status'])->toBe('pending_ui06_visual_review');
        expect($image['release_status'])->not->toContain('approved');
    }
});

it('keeps the TypeScript consumer derived from the same authority', function (): void {
    $manifest = ui05ImageManifest();
    $module = (string) file_get_contents(base_path('resources/spa/src/content/generated/landingImages.generated.ts'));

    expect($module)->toContain('GENERATED FILE — do not edit by hand.');
    expect($module)->toContain('config/landing-image-selection.json');

    /** @var list<array<string, mixed>> $images */
    $images = $manifest['images'];
    expect(substr_count($module, 'sourcePublicPath: "'))->toBe(count($images));

    foreach ($images as $image) {
        expect($module)->toContain('sourcePublicPath: "'.$image['source_public_path'].'"');
        expect($module)->toContain('alternativeText: '.json_encode($image['alternative_text'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
});
