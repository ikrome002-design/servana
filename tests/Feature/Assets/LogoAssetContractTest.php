<?php

declare(strict_types=1);

uses()->group('docs', 'ui05', 'contracts', 'assets', 'brand');

/*
 |==============================================================================
 | Phase UI-05 — approved logo mapping.
 |
 | One approved logo, referenced by public path from all eight accounts. The two failure modes this
 | guards are opposite and both real: copying the logo into eight per-role directories (which then
 | drift), and restoring `Logo.svg`, which the product owner deleted under authority and which
 | earlier revisions of the agent guide still listed as required.
 */

it('records one approved logo, measured from the file itself', function (): void {
    $manifest = ui05Audit('logo-manifest');

    /** @var array<string, mixed> $logo */
    $logo = $manifest['logo'];
    $path = base_path((string) $logo['repository_path']);

    expect($logo['repository_path'])->toBe('public/assets/brand/Logo.png');
    expect($logo['public_path'])->toBe('/assets/brand/Logo.png');
    expect($logo['mime_type'])->toBe('image/png');
    expect(file_exists($path))->toBeTrue();

    $bytes = (string) file_get_contents($path);
    expect(hash('sha256', $bytes))->toBe($logo['sha256']);
    expect(strlen($bytes))->toBe($logo['bytes']);

    $size = getimagesize($path);
    expect($size)->not->toBeFalse();
    expect($size[0])->toBe($logo['intrinsic_width']);
    expect($size[1])->toBe($logo['intrinsic_height']);
    expect($size[2])->toBe(IMAGETYPE_PNG);

    // Exact-case existence: on Linux and CI, `logo.png` and `Logo.png` are different files.
    $names = array_map('basename', glob(base_path('public/assets/brand/*.png')) ?: []);
    expect($names)->toContain('Logo.png');
    expect($names)->not->toContain('logo.png');
});

it('maps all eight accounts to the same approved logo with no per-role copy', function (): void {
    $manifest = ui05Audit('logo-manifest');

    /** @var list<array<string, mixed>> $accounts */
    $accounts = $manifest['accounts'];
    expect($accounts)->toHaveCount(8);

    $keys = [];
    foreach ($accounts as $account) {
        $keys[] = (string) $account['account_key'];
        expect($account['logo_public_path'])->toBe('/assets/brand/Logo.png');
        expect($account['override'])->toBeNull();
    }

    sort($keys);
    $expected = UI05_ACCOUNTS;
    sort($expected);
    expect($keys)->toBe($expected);

    // No account directory may hold its own logo copy.
    foreach (UI05_ACCOUNTS as $account) {
        $copies = glob(base_path("public/assets/landing_page_images/{$account}/Logo.*")) ?: [];
        expect($copies)->toBe([], "{$account} carries its own logo copy.");
    }
});

it('keeps the authorised Logo.svg deletion in force and unreferenced', function (): void {
    expect(file_exists(base_path('public/assets/brand/Logo.svg')))->toBeFalse(
        'Logo.svg was deleted under product-owner authority and must not be restored.',
    );

    $manifest = ui05Audit('logo-manifest');
    /** @var array<string, mixed> $forbidden */
    $forbidden = $manifest['forbidden'];
    expect($forbidden['repository_path'])->toBe('public/assets/brand/Logo.svg');
    expect($forbidden['state'])->toBe('absent');

    // No production source may reference it, in any case variant.
    $roots = [
        base_path('resources/spa/src'),
        base_path('resources/views'),
        base_path('app'),
        base_path('docker'),
    ];
    $offenders = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'vue', 'js', 'php', 'html', 'conf', 'blade'], true)) {
                continue;
            }
            // Specs legitimately NAME the file in order to assert it is absent; the contract is
            // about production source shipping a reference to it.
            if (str_contains($file->getFilename(), '.spec.') || str_contains($file->getFilename(), 'Test.php')) {
                continue;
            }
            // Comments are stripped first: the shared logo component and the nginx config both
            // EXPLAIN the authorised deletion, and a guard that fired on an explanation would
            // pressure the next author to delete the explanation rather than keep the rule.
            $code = (string) preg_replace(
                ['#/\*.*?\*/#s', '#^\s*//.*$#m', '#<!--.*?-->#s', '#^\s*\#.*$#m'],
                '',
                (string) file_get_contents($file->getPathname()),
            );
            if (preg_match('/logo\.svg/i', $code) === 1) {
                $offenders[] = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file->getPathname());
            }
        }
    }
    expect($offenders)->toBe([], 'Logo.svg is referenced by: '.implode(', ', $offenders));
});

it('states an alt-text policy that is not the file name', function (): void {
    $manifest = ui05Audit('logo-manifest');

    /** @var array<string, mixed> $logo */
    $logo = $manifest['logo'];
    expect($logo['alt_text_policy'])->toBeString();
    expect(strtolower((string) $logo['alt_text_policy']))->not->toContain('logo.png');
    expect($logo['source_approval'])->toBe('product_owner_approved');
});
