<?php

declare(strict_types=1);

uses()->group('infrastructure', 'health');

/*
 | Readiness probe happy path (Plan §22.1, §79 R7). `GET /health/deep` returns 200
 | only when every REQUIRED production dependency (database, redis, cache, s3) is
 | healthy. The s3 probe reports configured-disk readiness in tests (endpoint
 | cleared in tests/bootstrap.php), and Meilisearch is optional (Phase 22) so its
 | absence only degrades — never fails — readiness.
 */

it('returns 200 with every required dependency healthy', function (): void {
    $response = $this->get('/health/deep');

    $response->assertOk()
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.redis.status', 'ok')
        ->assertJsonPath('checks.cache.status', 'ok')
        ->assertJsonStructure([
            'status', 'service', 'timestamp',
            'checks' => ['app', 'database', 'redis', 'cache', 'queue', 'meilisearch', 's3'],
        ]);

    // s3 is required and must be healthy (ok or, if unconfigured locally, skipped).
    expect($response->json('checks.s3.status'))->toBeIn(['ok', 'skipped']);
    // Overall status is ok when all checks pass, or degraded if only an OPTIONAL
    // dependency (e.g. Meilisearch in CI) is down — but never below 200 here.
    expect($response->json('status'))->toBeIn(['ok', 'degraded']);
});

it('keeps an optional Meilisearch failure from failing readiness', function (): void {
    // Configure a Meilisearch host that resolves to a closed port → probe errors,
    // but Meilisearch is OPTIONAL (search lands Phase 22), so readiness stays 200.
    config(['services.meilisearch.host' => 'http://127.0.0.1:1']);

    $response = $this->get('/health/deep');

    $response->assertOk();
    expect($response->json('checks.meilisearch.status'))->toBe('error')
        ->and($response->json('status'))->toBe('degraded');
});
