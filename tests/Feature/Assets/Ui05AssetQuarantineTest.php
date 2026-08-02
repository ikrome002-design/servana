<?php

declare(strict_types=1);

uses()->group('docs', 'ui05', 'contracts', 'assets', 'brand');

/*
 |==============================================================================
 | Phase UI-05 — UI01-ASSET-002 closure.
 |
 | UI-01 proved eleven unapproved brand working files shipped inside the public web root of the
 | production image and were publicly served. UI/UX plan §17 permits only approved brand assets
 | there.
 |
 | The correction is a NON-DESTRUCTIVE quarantine: the bytes were moved with `git mv` into a
 | source-controlled archive under `docs/`, which is never copied into the nginx image. Nothing was
 | deleted, so the decision is reversible by the product owner; nothing is served, so the defect is
 | closed.
 |
 | These assertions prove both halves — absent from the public tree AND preserved byte-identical in
 | the archive. Proving only the first would be indistinguishable from having deleted them.
 */

it('quarantines exactly the eleven files UI-01 recorded', function (): void {
    $record = ui05QuarantineRecord();
    $manifest = ui05Audit('asset-quarantine');

    expect($record['defect_id'])->toBe('UI01-ASSET-002');
    expect($record['action'])->toBe('non_destructive_quarantine');
    expect($record['total_files'])->toBe(11);
    expect($manifest['total_files'])->toBe(11);
    expect($manifest['defect_id'])->toBe('UI01-ASSET-002');

    /** @var list<array<string, mixed>> $files */
    $files = $record['files'];
    $originals = array_map(static fn (array $file): string => (string) $file['original_path'], $files);

    expect($originals)->toContain('public/assets/brand/PNG.png');
    expect(array_filter($originals, static fn (string $path): bool => str_starts_with($path, 'public/assets/brand/v1/')))
        ->toHaveCount(10);
    expect(array_unique($originals))->toHaveCount(11);
});

it('preserves every quarantined file byte-identically', function (): void {
    $record = ui05QuarantineRecord();

    /** @var list<array<string, mixed>> $files */
    $files = $record['files'];
    foreach ($files as $file) {
        $archived = base_path((string) $file['quarantine_path']);

        expect(file_exists($archived))->toBeTrue("Quarantined file is missing (it must be moved, never deleted): {$file['quarantine_path']}");

        $bytes = (string) file_get_contents($archived);
        expect(hash('sha256', $bytes))->toBe($file['sha256'], "{$file['quarantine_path']} changed in the archive.");
        expect(strlen($bytes))->toBe($file['bytes']);

        expect(file_exists(base_path((string) $file['original_path'])))->toBeFalse(
            "{$file['original_path']} is still inside the publicly served brand tree.",
        );
        expect($file['quarantine_path'])->toStartWith('docs/brand/quarantine/ui01-asset-002/');
    }
});

it('leaves only approved assets under the served brand tree', function (): void {
    $present = array_map('basename', array_filter(
        glob(base_path('public/assets/brand/*')) ?: [],
        'is_file',
    ));
    sort($present);

    $expected = UI05_PROTECTED_BRAND_FILES;
    sort($expected);

    expect($present)->toBe($expected, 'Unapproved files under public/assets/brand: '
        .implode(', ', array_diff($present, $expected)));

    // The v1/ working directory must be gone entirely, not merely emptied of tracked files.
    expect(is_dir(base_path('public/assets/brand/v1')))->toBeFalse();
});

it('leaves every protected brand asset untouched', function (): void {
    $manifest = ui05Audit('asset-quarantine');

    /** @var list<array<string, mixed>> $protected */
    $protected = $manifest['protected_assets_untouched'];
    expect($protected)->toHaveCount(count(UI05_PROTECTED_BRAND_FILES));

    foreach ($protected as $asset) {
        $path = base_path((string) $asset['path']);
        expect($asset['present'])->toBeTrue();
        expect(file_exists($path))->toBeTrue();
        expect(hash('sha256', (string) file_get_contents($path)))->toBe($asset['sha256']);
    }
});

it('keeps the archive outside every publicly served tree and out of the nginx image', function (): void {
    $manifest = ui05Audit('asset-quarantine');
    expect($manifest['quarantine_publicly_served'])->toBeFalse();

    $record = ui05QuarantineRecord();
    /** @var list<array<string, mixed>> $files */
    $files = $record['files'];
    foreach ($files as $file) {
        expect(str_starts_with((string) $file['quarantine_path'], 'public/'))->toBeFalse();
    }

    // The edge image copies `public/` and the built SPA — never `docs/`.
    $dockerfile = (string) file_get_contents(base_path('docker/nginx.Dockerfile'));
    expect($dockerfile)->not->toContain('docs/');
    expect($dockerfile)->toContain('COPY --chown=nginx:nginx public /var/www/html/public');
});

it('holds the closure at local completion until the pull request merges', function (): void {
    $manifest = ui05Audit('asset-quarantine');

    expect($manifest['closure_status'])->toBe('local_complete pending PR CI/review/merge');
    expect($manifest['closure_status'])->not->toBe('verified_complete');
});

it('never references a quarantined path from production source', function (): void {
    $record = ui05QuarantineRecord();
    /** @var list<array<string, mixed>> $files */
    $files = $record['files'];

    $roots = [base_path('resources/spa/src'), base_path('resources/views'), base_path('app'), base_path('docker')];
    $offenders = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            foreach ($files as $quarantined) {
                $url = (string) $quarantined['original_public_url'];
                if (str_contains($text, $url)) {
                    $offenders[] = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file->getPathname()).' → '.$url;
                }
            }
        }
    }

    expect($offenders)->toBe([], 'Quarantined assets are still referenced: '.implode(', ', $offenders));
});
