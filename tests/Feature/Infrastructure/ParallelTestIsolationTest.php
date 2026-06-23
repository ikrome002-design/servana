<?php

declare(strict_types=1);

uses()->group('infrastructure', 'isolation');

/*
 | Parallel-test isolation (Plan §79 R7; REM-OPS-001). The Redis/cache namespace
 | is derived from the CI run id + the parallel-test token, so each parallel
 | worker and each CI run is isolated. Two distinct tokens always yield distinct
 | namespaces — one worker can never read or clear another's keys.
 */

/** Mirror of the derivation in tests/bootstrap.php (the single source of truth). */
function deriveNamespace(string $runId, string $token): string
{
    return 'servana_test_'.$runId.'_'.$token.'_';
}

it('exposes a single namespace shared by the Redis and cache prefixes', function (): void {
    $namespace = (string) getenv('SERVANA_TEST_NAMESPACE');

    // The Redis connection prefix is exactly our namespace; the cache prefix
    // starts with it (Laravel's parallel TestCaches appends its own token per
    // worker — additional, not conflicting, isolation).
    expect($namespace)->toStartWith('servana_test_')
        ->and((string) config('database.redis.options.prefix'))->toBe($namespace)
        ->and((string) config('cache.prefix'))->toStartWith($namespace);
});

it('derives distinct namespaces for distinct parallel tokens', function (): void {
    $runId = 'run123';

    expect(deriveNamespace($runId, '1'))
        ->not->toBe(deriveNamespace($runId, '2'))
        ->and(deriveNamespace($runId, '1'))->not->toBe(deriveNamespace('run999', '1'));
});

it('reflects the active parallel token in the namespace when running in parallel', function (): void {
    $token = getenv('TEST_TOKEN') ?: getenv('LARAVEL_PARALLEL_TESTING_TOKEN');

    if ($token === false || $token === '') {
        // Not a parallel run: the namespace falls back to the process id, which is
        // still unique per process.
        expect((string) getenv('SERVANA_TEST_NAMESPACE'))->toContain('_'.getmypid().'_');

        return;
    }

    expect((string) getenv('SERVANA_TEST_NAMESPACE'))->toContain('_'.$token.'_');
});
