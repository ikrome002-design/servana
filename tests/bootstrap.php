<?php

declare(strict_types=1);

/*
 | PHPUnit bootstrap.
 |
 | The Docker dev containers inject runtime config (APP_ENV=local,
 | CACHE_STORE=redis, SESSION_DRIVER=database, …) as real environment variables,
 | which land in $_SERVER. Laravel's env reader prioritises $_SERVER, so a plain
 | phpunit <env> entry (which only sets $_ENV/putenv) cannot override them and the
 | suite would run against the shared redis cache — making the rate limiters bleed
 | across runs and sessions non-deterministic.
 |
 | Overriding $_SERVER/$_ENV here, before the framework boots, guarantees the test
 | environment regardless of how the process was launched (host, Docker, or CI).
 */
$forcedTestEnv = [
    'APP_ENV' => 'testing',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    // Phase UI-03: SANCTUM_STATEFUL_DOMAINS is deliberately NOT forced here.
    //
    // It used to be pinned to `localhost`, which made every one of the eight account hosts
    // NON-stateful under test: a Magic Link verify on `finance.servana.test` returned 200 with no
    // session cookie at all, because Sanctum never applied StartSession. That silently disabled
    // the exact behaviour the host-scoped session tests exist to prove. The value now comes from
    // config/sanctum.php, which derives it from the canonical account-host registry (and still
    // includes `localhost` for the pre-UI-03 call sites).
    //
    // The local port must be pinned instead, so the derived `host:port` forms match the absolute
    // URLs the test helpers build.
    'ACCOUNT_HOST_LOCAL_PORT' => '8080',
    // The readiness probe must not make a live object-store round-trip during
    // tests (CI has no MinIO). Clearing the S3 endpoint makes the s3 probe report
    // configured-disk readiness ('ok') without a network call (Plan §79 R7); the
    // dependency-failure test exercises the error path explicitly.
    'AWS_ENDPOINT' => '',
];

/*
 | Per-run, per-process namespace isolation (Plan §79 R7; REM-OPS-001).
 |
 | Cache/session/queue already use array/sync drivers in tests (in-memory, per
 | process — no shared store, no FLUSHDB), so they are inherently isolated. To
 | also isolate any DIRECT Redis usage and the CI shared Redis instance, every
 | test PROCESS (incl. each parallel worker) and every CI RUN gets a unique Redis
 | + cache key prefix. Two namespaces can use identical logical keys without
 | colliding, and a cache clear is scoped to the current namespace only.
 */
$parallelToken = getenv('TEST_TOKEN')
    ?: getenv('LARAVEL_PARALLEL_TESTING_TOKEN')
    ?: (string) getmypid();
$runId = getenv('CI_TEST_RUN_ID')
    ?: getenv('GITHUB_RUN_ID')
    ?: getenv('GITHUB_RUN_ATTEMPT')
    ?: 'local';
$namespace = 'servana_test_'.$runId.'_'.$parallelToken.'_';

$forcedTestEnv['REDIS_PREFIX'] = $namespace;
$forcedTestEnv['CACHE_PREFIX'] = $namespace;
$forcedTestEnv['SERVANA_TEST_NAMESPACE'] = $namespace;

foreach ($forcedTestEnv as $key => $value) {
    $_SERVER[$key] = $value;
    $_ENV[$key] = $value;
    putenv("{$key}={$value}");
}

require __DIR__.'/../vendor/autoload.php';
