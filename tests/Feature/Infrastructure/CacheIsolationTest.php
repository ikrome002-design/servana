<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

uses()->group('infrastructure', 'isolation');

/*
 | Cache isolation (Plan §79 R7; REM-OPS-001). Tests use the array store
 | (in-memory, per process — no shared backend, no FLUSHDB). The cache PREFIX is
 | additionally namespaced per process/run so any Redis-backed cache usage is
 | isolated; a clear is scoped to the namespace, never a global flush.
 */

it('namespaces the cache prefix per process and run', function (): void {
    // The cache prefix starts with our per-run/process namespace. Under parallel
    // testing Laravel additionally appends its own token (Illuminate TestCaches),
    // layering a second per-worker boundary — so we assert the namespace ROOT,
    // which holds in both serial and parallel runs.
    expect((string) config('cache.prefix'))
        ->toStartWith((string) getenv('SERVANA_TEST_NAMESPACE'));
});

it('uses an in-memory array store in tests (no shared backend, no FLUSHDB)', function (): void {
    expect(config('cache.default'))->toBe('array');

    Cache::put('iso-key', 'v', 60);
    expect(Cache::get('iso-key'))->toBe('v');

    // A flush clears only this process's in-memory store — it cannot reach,
    // clear or consume another process's or another namespace's keys.
    Cache::flush();
    expect(Cache::get('iso-key'))->toBeNull();
});

it('keeps two prefixed cache repositories from colliding on identical keys', function (): void {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        test()->markTestSkipped('Redis is not reachable in this environment.');
    }

    /** @var CacheManager $manager */
    $manager = app('cache');

    $pidA = 'r7_cache_a_'.getmypid().'_';
    $pidB = 'r7_cache_b_'.getmypid().'_';

    config(['cache.stores.redis_a' => ['driver' => 'redis', 'connection' => 'cache', 'prefix' => $pidA]]);
    config(['cache.stores.redis_b' => ['driver' => 'redis', 'connection' => 'cache', 'prefix' => $pidB]]);

    $manager->store('redis_a')->put('shared', 'A', 60);
    $manager->store('redis_b')->put('shared', 'B', 60);

    expect($manager->store('redis_a')->get('shared'))->toBe('A')
        ->and($manager->store('redis_b')->get('shared'))->toBe('B');

    $manager->store('redis_a')->forget('shared');
    $manager->store('redis_b')->forget('shared');
});
