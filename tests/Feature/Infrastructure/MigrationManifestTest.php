<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

uses()->group('infrastructure', 'migrations');

/*
 | Migration manifest lint (ADR-004; Plan §13.1-§13.3, §80 Phase 10 / REM-MIG-001).
 | Keeps docs/architecture/migrations/manifest.yaml honest against the migrations on
 | disk: nothing missing, nothing dangling, every business migration carries a
 | data-dictionary reference, destructive changes carry a forward-repair plan,
 | dependencies are valid, and there are no duplicates.
 */

function manifest(): array
{
    return Yaml::parseFile(base_path('docs/architecture/migrations/manifest.yaml'));
}

/** @return list<string> */
function migrationFilesOnDisk(): array
{
    $files = array_map('basename', glob(base_path('database/migrations/*.php')) ?: []);
    sort($files);

    return $files;
}

/** @return list<string> all files declared anywhere in the manifest */
function manifestFiles(array $manifest): array
{
    $framework = array_column($manifest['framework_migrations'] ?? [], 'file');
    $business = array_column($manifest['business_migrations'] ?? [], 'file');

    return [...$framework, ...$business];
}

it('lists every migration on disk (none missing)', function (): void {
    $declared = manifestFiles(manifest());
    $missing = array_values(array_diff(migrationFilesOnDisk(), $declared));

    expect($missing)->toBe([]);
});

it('references no migration that does not exist on disk', function (): void {
    $onDisk = migrationFilesOnDisk();
    $dangling = array_values(array_diff(manifestFiles(manifest()), $onDisk));

    expect($dangling)->toBe([]);
});

it('contains no duplicate migration entries', function (): void {
    $all = manifestFiles(manifest());
    $dupes = array_values(array_unique(array_diff_assoc($all, array_unique($all))));

    expect($dupes)->toBe([]);
});

it('gives every business migration a data-dictionary reference that exists', function (): void {
    $violations = [];

    foreach (manifest()['business_migrations'] ?? [] as $entry) {
        $ref = $entry['data_dictionary'] ?? null;

        if (! is_string($ref) || $ref === '' || ! file_exists(base_path($ref))) {
            $violations[] = ($entry['file'] ?? '?').' => '.var_export($ref, true);
        }
    }

    expect($violations)->toBe([]);
});

it('requires a forward-repair plan for any destructive change', function (): void {
    $violations = [];

    foreach (manifest()['business_migrations'] ?? [] as $entry) {
        if (($entry['destructive'] ?? false) === true) {
            $plan = $entry['forward_repair'] ?? null;

            if (! is_string($plan) || trim($plan) === '') {
                $violations[] = $entry['file'] ?? '?';
            }
        }
    }

    expect($violations)->toBe([]);
});

it('only declares dependencies that are themselves in the manifest', function (): void {
    $manifest = manifest();
    $known = manifestFiles($manifest);
    $violations = [];

    foreach ($manifest['business_migrations'] ?? [] as $entry) {
        foreach ($entry['depends_on'] ?? [] as $dependency) {
            if (! in_array($dependency, $known, true)) {
                $violations[] = ($entry['file'] ?? '?').' -> '.$dependency;
            }
        }
    }

    expect($violations)->toBe([]);
});
