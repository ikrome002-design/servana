<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;

uses()->group('infrastructure', 'isolation');

/*
 | Redis key-prefix isolation (Plan §79 R7; REM-OPS-001). Every test process and
 | CI run gets a unique Redis prefix (tests/bootstrap.php), so two namespaces can
 | use identical logical keys without collision and a clean-up never needs FLUSHDB.
 */

/** Skip cleanly when no Redis is reachable (e.g. a bare host without the stack). */
function redisAvailable(): bool
{
    try {
        Redis::connection()->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}

/** A raw phpredis client bound to a given key prefix (proves the OPT_PREFIX mechanism). */
function prefixedRedisClient(string $prefix): \Redis
{
    $client = new \Redis;
    $client->connect((string) config('database.redis.default.host'), (int) config('database.redis.default.port'));
    $password = config('database.redis.default.password');
    if (filled($password)) {
        $client->auth((string) $password);
    }
    $client->setOption(\Redis::OPT_PREFIX, $prefix);

    return $client;
}

beforeEach(function (): void {
    if (! extension_loaded('redis') || ! redisAvailable()) {
        test()->markTestSkipped('Redis (phpredis) is not reachable in this environment.');
    }
});

it('uses a unique per-process Redis prefix derived from the run + parallel token', function (): void {
    $prefix = (string) config('database.redis.options.prefix');

    expect($prefix)->toStartWith('servana_test_')
        ->and($prefix)->toBe((string) getenv('SERVANA_TEST_NAMESPACE'));
});

it('keeps identical logical keys isolated across two prefixes (no collision)', function (): void {
    $a = prefixedRedisClient('r7_iso_a_'.getmypid().'_');
    $b = prefixedRedisClient('r7_iso_b_'.getmypid().'_');

    // The SAME logical key written under two namespaces must not collide.
    $a->set('isolation-probe', 'value-A');
    $b->set('isolation-probe', 'value-B');

    expect($a->get('isolation-probe'))->toBe('value-A')
        ->and($b->get('isolation-probe'))->toBe('value-B');

    // One namespace cannot clear the other's key — and we never FLUSHDB.
    $a->del('isolation-probe');
    expect($a->get('isolation-probe'))->toBeFalse()
        ->and($b->get('isolation-probe'))->toBe('value-B');

    $b->del('isolation-probe');
});
