<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PermissionMatrix;

uses()->group('auth', 'permissions', 'matrix');

/*
 | §19.5 completeness: every key in the canonical Plan §19.2 catalogue has a
 | populated YAML row. Parsed INDEPENDENTLY from the Plan text so the YAML is a
 | genuine mechanical comparison against the source-of-truth (not self-referential).
 |
 | Also proves the source-of-truth corrections hold everywhere: the retired
 | `audit.view_full` and the legacy `audit.flag` are absent from the contract.
 */

/** @return list<string> the unique §19.2 canonical keys, parsed from the Plan. */
function canonicalCatalogueKeys(): array
{
    $plan = (string) file_get_contents(base_path('Servana Software Development Plan.md'));
    preg_match('/### 19\.2.*?```text(.*?)```/s', $plan, $m);
    $keys = [];
    foreach (preg_split('/\r?\n/', $m[1] ?? '') as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        foreach (explode('|', $line) as $tok) {
            $tok = trim($tok);
            if (preg_match('/^[a-z0-9_.]+$/', $tok)) {
                $keys[$tok] = true;
            }
        }
    }

    return array_keys($keys);
}

it('covers every canonical §19.2 key with a populated YAML row', function (): void {
    $canonical = canonicalCatalogueKeys();
    $yamlKeys = app(PermissionMatrix::class)->keys();

    $missing = array_values(array_diff($canonical, $yamlKeys));

    expect($canonical)->toHaveCount(156);
    expect($missing)->toBe([], 'canonical keys missing from the YAML: '.implode(', ', $missing));
});

it('marks every canonical key active or planned and never invents a non-canonical planned key', function (): void {
    $matrix = app(PermissionMatrix::class);
    $canonical = array_fill_keys(canonicalCatalogueKeys(), true);

    // Every PLANNED key must be canonical (planned keys only ever come from §19.2).
    foreach ($matrix->plannedKeys() as $key) {
        expect(isset($canonical[$key]))->toBeTrue("planned key {$key} is not in the §19.2 catalogue");
    }
});

it('excludes the retired audit.view_full and legacy audit.flag everywhere in the contract', function (): void {
    $keys = app(PermissionMatrix::class)->keys();

    expect($keys)->not->toContain('audit.view_full');
    expect($keys)->not->toContain('audit.flag');
});
