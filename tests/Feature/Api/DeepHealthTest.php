<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('keeps /health dependency-free and always 200', function (): void {
    $this->get('/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('service', 'servana')
        ->assertJsonStructure(['status', 'service', 'timestamp']);
});

it('reports readiness of each dependency at /health/deep', function (): void {
    $response = $this->get('/health/deep');

    // Required dependencies (db, redis, cache) are up in the test environment,
    // so the probe returns 200. Optional deps (meilisearch, s3) may be skipped
    // or degraded depending on the environment.
    $response->assertOk()
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.redis.status', 'ok')
        ->assertJsonPath('checks.cache.status', 'ok')
        ->assertJsonStructure([
            'status',
            'service',
            'timestamp',
            'checks' => ['app', 'database', 'redis', 'cache', 'queue', 'meilisearch', 's3'],
        ]);

    expect($response->json('status'))->toBeIn(['ok', 'degraded']);
});

it('never leaks credentials or exception details in the deep probe body', function (): void {
    // Use a UNIQUE sentinel as the configured DB password so this assertion
    // cannot collide with a common word that legitimately appears in the body
    // (the CI password is "servana", which is also the service name). The probe
    // must never echo the configured database password into its response.
    $sentinel = '__phase3_unique_db_password_should_not_leak__';
    config(['database.connections.pgsql.password' => $sentinel]);
    // Force the probe to actually use the modified config: the DB connection
    // then fails to authenticate, so the probe must degrade gracefully without
    // surfacing the password, the SQL state, or any raw exception detail.
    DB::purge('pgsql');

    $body = (string) $this->get('/health/deep')->getContent();

    expect($body)
        ->not->toContain($sentinel)
        ->not->toContain('secret')
        ->not->toContain('password')
        ->not->toContain('Bearer ')
        ->not->toContain('SQLSTATE');
});
