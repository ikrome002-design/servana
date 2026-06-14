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
    'SANCTUM_STATEFUL_DOMAINS' => 'localhost',
];

foreach ($forcedTestEnv as $key => $value) {
    $_SERVER[$key] = $value;
    $_ENV[$key] = $value;
    putenv("{$key}={$value}");
}

require __DIR__.'/../vendor/autoload.php';
