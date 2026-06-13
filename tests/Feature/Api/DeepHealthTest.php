<?php

declare(strict_types=1);

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
    $body = $this->get('/health/deep')->getContent();

    expect($body)
        ->not->toContain('secret')
        ->not->toContain('password')
        ->not->toContain(config('database.connections.pgsql.password') ?: '__no_db_password__');
});
