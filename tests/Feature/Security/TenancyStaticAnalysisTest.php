<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

uses()->group('security', 'tenancy');

/*
 | Lightweight source scan (Plan §8.2, §27 Phase 9) — a fast complement to the
 | PHPStan rules NoWithoutTenancyOutsidePlatformRule / NoRawSqlConcatRule. It
 | catches tenant-isolation escape hatches and raw-SQL concatenation that slip
 | into application code, so a regression is a red test even before CI runs stan.
 */

/** @return list<string> */
function appPhpFiles(string $subdir = ''): array
{
    $base = base_path('app'.($subdir !== '' ? '/'.$subdir : ''));

    return collect(File::allFiles($base))
        ->filter(fn ($f): bool => $f->getExtension() === 'php')
        ->map(fn ($f): string => $f->getRealPath())
        ->values()
        ->all();
}

function isTenancyOrPlatform(string $path): bool
{
    $normalized = str_replace('\\', '/', $path);

    return str_contains($normalized, '/app/Domain/Tenancy/')
        || str_contains($normalized, '/app/Domain/Platform/')
        // The PHPStan rule classes themselves contain the banned tokens as the
        // detection patterns they match — they are not call sites.
        || str_contains($normalized, '/app/Support/Phpstan/');
}

it('has no withoutTenancy/withoutGlobalScope outside Tenancy or Platform code', function (): void {
    $offenders = [];

    foreach (appPhpFiles() as $path) {
        if (isTenancyOrPlatform($path)) {
            continue;
        }

        $contents = (string) file_get_contents($path);

        if (preg_match('/->\s*withoutTenancy\s*\(|::\s*withoutTenancy\s*\(/', $contents)
            || preg_match('/withoutGlobalScopes?\s*\(/', $contents)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Tenant-scope escape hatch found in: '.implode(', ', $offenders));
});

it('has no unscoped Model::find() in controllers', function (): void {
    $offenders = [];

    foreach (appPhpFiles('Http/Controllers') as $path) {
        $contents = (string) file_get_contents($path);

        // ::find( / ::findOrFail( on request input bypasses tenant scope — use
        // route-model binding (scoped) or an explicitly scoped query instead.
        if (preg_match('/::\s*find(OrFail)?\s*\(/', $contents)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Unscoped ::find() in controller(s): '.implode(', ', $offenders));
});

it('has no raw SQL built by concatenation/interpolation in app code', function (): void {
    $offenders = [];
    $rawCalls = '(whereRaw|orWhereRaw|havingRaw|orderByRaw|groupByRaw|selectRaw|fromRaw|DB::raw|DB::statement|DB::select|DB::unprepared)';

    foreach (appPhpFiles() as $path) {
        if (isTenancyOrPlatform($path)) {
            continue; // skip the rule classes (they contain the patterns as data)
        }

        $contents = (string) file_get_contents($path);

        // A raw-SQL call whose argument concatenates ('.$x) or interpolates ({$x}).
        if (preg_match('/'.$rawCalls.'\s*\(\s*[^)]*(\.\s*\$|\{\s*\$)/', $contents)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Raw SQL concatenation in: '.implode(', ', $offenders));
});
