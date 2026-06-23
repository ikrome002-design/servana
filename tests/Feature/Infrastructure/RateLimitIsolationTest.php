<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;

uses()->group('infrastructure', 'isolation');

/*
 | Rate-limit isolation (Plan §79 R7; REM-OPS-001). The limiter is backed by the
 | namespaced cache, so counters do not bleed between distinct keys, between test
 | processes, or between CI runs. Each test boots a fresh application (fresh array
 | store), so counters never bleed across tests either.
 */

it('does not bleed counts between distinct limiter keys', function (): void {
    RateLimiter::hit('ns:login:a', 60);
    RateLimiter::hit('ns:login:a', 60);

    expect(RateLimiter::attempts('ns:login:a'))->toBe(2)
        // An unrelated key starts fresh — no cross-key bleed.
        ->and(RateLimiter::attempts('ns:login:b'))->toBe(0);

    RateLimiter::clear('ns:login:a');
    expect(RateLimiter::attempts('ns:login:a'))->toBe(0);
});

it('starts every test with fresh limiter state (no cross-test bleed)', function (): void {
    // If counters bled from the previous test, this key would be non-zero.
    expect(RateLimiter::attempts('ns:login:a'))->toBe(0);

    foreach (range(1, 3) as $ignored) {
        RateLimiter::hit('ns:login:a', 60);
    }
    expect(RateLimiter::attempts('ns:login:a'))->toBe(3);
});

it('keys the limiter through the namespaced cache prefix', function (): void {
    // The limiter resolves its store from the cache; the cache prefix is the
    // per-process namespace, so two processes cannot share a limiter counter.
    expect((string) config('cache.prefix'))->toStartWith('servana_test_');
});
