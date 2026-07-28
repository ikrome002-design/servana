<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

uses()->group('performance', 'cache-scope', 'security');

/*
|--------------------------------------------------------------------------
| Phase 24 - cache scope forward guard (Plan §68, §69, §72, §74)
|--------------------------------------------------------------------------
|
| The Phase 24 audit established that Servana performs NO application data or
| response caching: every read is served from PostgreSQL. The only cache calls in
| `app/` are the health probe, and Redis otherwise backs sessions, queues, locks
| and rate limiting.
|
| So there are no mis-scoped keys to correct. The risk this guard addresses is the
| FUTURE one: Plan §69 requires that "cached report keys include merchant+branch+
| role/masking+filters+date range" and that Servana "never cache unscoped + filter
| client-side" (§68 says the same for search). A cache introduced later without
| those dimensions would leak across tenants, branches, roles or masking modes -
| and would do so silently, because a cache hit looks like a fast success.
|
| This guard therefore fails when a new cache call site appears in `app/` that is
| not one of the documented, reviewed exceptions. It is a static scan: no runtime
| overhead, no cache abstraction, and nothing for a future phase to work around
| accidentally.
|
| To ADD a legitimate cache, a future phase must (a) declare the call site here,
| and (b) prove key scoping and invalidation with behavioural tests - per the
| Phase 24 benchmark profile and Plan §69.
|
*/

/**
 * Documented, reviewed cache/Redis call sites. Key = repo-relative path; value = why it is safe.
 *
 * Anything NOT listed here is treated as a new application data cache and fails the guard.
 *
 * @var array<string, string>
 */
const P24_ALLOWED_CACHE_SITES = [
    // Liveness/readiness probe only: writes a fixed sentinel key and immediately forgets it. It
    // stores no tenant, branch, role or business data, and its value is never read back into a
    // response. See Plan §71 (observability) and the R7 production-probe work.
    'app/Http/Controllers/HealthController.php' => 'health/readiness probe sentinel — no business data',
];

/**
 * Call patterns that indicate a cache read/write. Deliberately broad: it is better for this guard
 * to catch a framework-adjacent use and have a reviewer document it than to miss a real data cache.
 *
 * @var list<string>
 */
const P24_CACHE_PATTERNS = [
    'Cache::',
    'cache()',
    '->remember(',
    '->rememberForever(',
    'rememberForever(',
    'Redis::',
    'Illuminate\\Contracts\\Cache\\Repository',
    'Psr\\SimpleCache',
];

/**
 * The dimensions a tenant-sensitive cache key MUST carry (Plan §69). Recorded here so the
 * requirement lives beside the guard that enforces the boundary, not only in prose.
 *
 * @var list<string>
 */
const P24_REQUIRED_CACHE_KEY_DIMENSIONS = [
    'merchant',
    'branch',
    'principal/own-scope',
    'role/capability or masking mode',
    'filters',
    'sort',
    'page/cursor',
    'date range',
    'currency',
    'resource version',
];

/** @return list<string> repo-relative paths of every PHP file under app/ */
function p24AppPhpFiles(): array
{
    $paths = [];
    foreach (File::allFiles(base_path('app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $paths[] = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $file->getPathname()), '\\/'));
    }
    sort($paths);

    return $paths;
}

/** @return array<string, list<string>> path => matching lines */
function p24CacheCallSites(): array
{
    $sites = [];

    foreach (p24AppPhpFiles() as $path) {
        $contents = (string) File::get(base_path($path));
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $index => $line) {
            // Ignore comment lines: a doc comment naming Cache:: is documentation, not a call.
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            foreach (P24_CACHE_PATTERNS as $pattern) {
                if (str_contains($line, $pattern)) {
                    $sites[$path][] = sprintf('%d: %s', $index + 1, trim($line));

                    continue 2;
                }
            }
        }
    }

    return $sites;
}

it('introduces no undeclared application cache', function (): void {
    $sites = p24CacheCallSites();
    $undeclared = array_diff_key($sites, P24_ALLOWED_CACHE_SITES);

    $report = [];
    foreach ($undeclared as $path => $lines) {
        $report[] = $path.PHP_EOL.'    '.implode(PHP_EOL.'    ', $lines);
    }

    expect($undeclared)->toBe([], implode(PHP_EOL, array_merge(
        ['A new cache call site appeared in app/. Servana caches no application data today.'],
        ['If this is a legitimate cache, declare it in P24_ALLOWED_CACHE_SITES and prove the key'],
        ['carries every applicable dimension ('.implode(', ', P24_REQUIRED_CACHE_KEY_DIMENSIONS).')'],
        ['plus its invalidation, with behavioural cross-tenant/cross-role tests (Plan §69).'],
        [''],
        $report,
    )));
});

it('keeps every declared cache exception actually present, so the allowlist cannot rot', function (): void {
    $sites = p24CacheCallSites();

    foreach (array_keys(P24_ALLOWED_CACHE_SITES) as $path) {
        expect(array_key_exists($path, $sites))->toBeTrue(
            "{$path} is declared as a cache exception but no longer contains a cache call. Remove it "
            .'from P24_ALLOWED_CACHE_SITES so the allowlist stays honest.',
        );
    }
});

it('records that Servana serves every read from PostgreSQL, with no data cache', function (): void {
    $sites = p24CacheCallSites();

    // Exactly one file, and it is the health probe. If this ever changes the phase that changed it
    // owns the scoping proof.
    expect(array_keys($sites))->toBe(['app/Http/Controllers/HealthController.php']);
});

it('keys every named rate limiter to a principal or IP, never a global bucket', function (): void {
    $provider = (string) File::get(base_path('app/Providers/AppServiceProvider.php'));

    preg_match_all("/RateLimiter::for\(\s*'([a-z0-9\-]+)'/", $provider, $names);
    $limiters = $names[1] ?? [];

    expect($limiters)->not->toBe([], 'No named rate limiters found — the parser drifted.');

    // Every Limit built in the provider must be bound with ->by(...). An unkeyed Limit is a single
    // global bucket shared by every tenant, which is both a correctness and an availability defect.
    preg_match_all('/Limit::per(?:Minute|Hour|Day)\([^)]*\)(->by\()?/', $provider, $limits);

    $unkeyed = array_values(array_filter(
        $limits[1] ?? [],
        static fn (string $by): bool => $by === '',
    ));

    expect($unkeyed)->toBe(
        [],
        'A named rate limiter builds a Limit without ->by(...), producing one global bucket shared '
        .'across all tenants and principals.',
    );
});
