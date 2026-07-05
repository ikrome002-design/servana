<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

uses()->group('infrastructure', 'files');

/*
 | Phase 10F migration-manifest coverage (ADR-004). The file-domain migrations touching
 | uploaded_files / file_scan_events must be inventoried with the Files domain, the
 | files-and-media data-dictionary reference, and valid dependencies. Phase 19 (ADR-010)
 | added a non-destructive expand/contract ALTER on uploaded_files (the audit_export
 | purpose) — a legitimate later Files-domain migration owned by Phase 19. (The global
 | MigrationManifestTest lints the whole file.)
 */

function fileManifestEntries(): array
{
    $manifest = Yaml::parseFile(base_path('docs/architecture/migrations/manifest.yaml'));

    return array_values(array_filter(
        $manifest['business_migrations'] ?? [],
        static fn (array $e): bool => str_contains((string) ($e['file'] ?? ''), 'uploaded_files')
            || str_contains((string) ($e['file'] ?? ''), 'file_scan_events'),
    ));
}

/** The two Phase-10F migrations that CREATE the file tables (change_type expand). */
function fileCreateEntries(): array
{
    return array_values(array_filter(
        fileManifestEntries(),
        static fn (array $e): bool => ($e['change_type'] ?? null) === 'expand',
    ));
}

it('inventories the file-domain migrations under the Files domain', function (): void {
    $entries = fileManifestEntries();

    // Two Phase-10F table creations + the Phase-19 audit_export purpose ALTER (ADR-010).
    expect($entries)->toHaveCount(3);

    foreach ($entries as $entry) {
        expect($entry['domain'])->toBe('Files')
            ->and($entry['owner_phase'])->toBeIn(['10F', '19'])
            ->and($entry['data_dictionary'])->toBe('docs/architecture/data-dictionary/files-and-media.md')
            ->and(file_exists(base_path($entry['data_dictionary'])))->toBeTrue()
            ->and($entry['destructive'])->toBeFalse();
    }
});

it('keeps the two Phase-10F file-table creations owned by 10F', function (): void {
    $creates = fileCreateEntries();

    expect($creates)->toHaveCount(2);

    foreach ($creates as $entry) {
        expect($entry['owner_phase'])->toBe('10F');
    }
});

it('all file migrations exist on disk', function (): void {
    foreach (fileManifestEntries() as $entry) {
        expect(file_exists(base_path('database/migrations/'.$entry['file'])))->toBeTrue();
    }
});
