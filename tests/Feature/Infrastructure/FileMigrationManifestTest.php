<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

uses()->group('infrastructure', 'files');

/*
 | Phase 10F migration-manifest coverage (ADR-004). The two file migrations must be
 | inventoried with the Files domain, the files-and-media data-dictionary reference,
 | and valid dependencies. (The global MigrationManifestTest lints the whole file.)
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

it('inventories both file migrations under the Files domain', function (): void {
    $entries = fileManifestEntries();

    expect($entries)->toHaveCount(2);

    foreach ($entries as $entry) {
        expect($entry['domain'])->toBe('Files')
            ->and($entry['owner_phase'])->toBe('10F')
            ->and($entry['data_dictionary'])->toBe('docs/architecture/data-dictionary/files-and-media.md')
            ->and(file_exists(base_path($entry['data_dictionary'])))->toBeTrue()
            ->and($entry['destructive'])->toBeFalse();
    }
});

it('both file migrations exist on disk', function (): void {
    foreach (fileManifestEntries() as $entry) {
        expect(file_exists(base_path('database/migrations/'.$entry['file'])))->toBeTrue();
    }
});
