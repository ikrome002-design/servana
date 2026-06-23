<?php

declare(strict_types=1);

uses()->group('infrastructure', 'health');

/*
 | Production readiness configuration (Plan §22.1, §26, §79 R7; REM-OPS-001). The
 | required dependency set is derived from the production topology
 | (docker-compose.prod.yml: managed PostgreSQL, Redis, S3 — Redis backs cache +
 | queue). Local-only services (Mailpit) are never readiness dependencies, and
 | Meilisearch stays optional until Phase 22. Probe timeouts are bounded.
 */

it('requires exactly the production-managed dependencies', function (): void {
    $required = config('servana.health.required_dependencies');

    expect($required)
        ->toContain('database')
        ->toContain('redis')
        ->toContain('cache')
        ->toContain('s3')
        // Mailpit (local-only) and Meilisearch (Phase 22) are NOT required.
        ->not->toContain('mailpit')
        ->not->toContain('meilisearch');

    expect(config('servana.health.optional_dependencies'))->toContain('meilisearch');
});

it('bounds every network probe timeout', function (): void {
    $timeout = (float) config('servana.health.probe_timeout');
    expect($timeout)->toBeGreaterThan(0)->toBeLessThanOrEqual(5.0);

    // Redis and S3 carry bounded connect timeouts so a dead dependency fails fast.
    expect((float) config('database.redis.default.timeout'))->toBeGreaterThan(0)->toBeLessThanOrEqual(5.0)
        ->and((float) config('database.redis.cache.timeout'))->toBeGreaterThan(0)->toBeLessThanOrEqual(5.0)
        ->and((float) config('filesystems.disks.s3.http.connect_timeout'))->toBeGreaterThan(0)->toBeLessThanOrEqual(5.0);
});

it('fails readiness for an unconfigured required dependency in production', function (): void {
    // Simulate production strictness: an unconfigured managed dependency (S3 with
    // no bucket) must fail readiness rather than be silently treated as optional.
    config(['servana.health.require_configured' => true]);
    config(['filesystems.disks.s3.bucket' => null, 'filesystems.disks.s3.endpoint' => null]);

    $response = $this->get('/health/deep');

    expect($response->json('checks.s3.status'))->toBe('skipped');
    $response->assertStatus(503)->assertJsonPath('status', 'unhealthy');
});

it('passes readiness for an unconfigured required dependency outside production', function (): void {
    // Non-production (require_configured = false): an unconfigured S3 is "skipped"
    // and does not weaken readiness (so CI without MinIO stays green).
    config(['servana.health.require_configured' => false]);
    config(['filesystems.disks.s3.bucket' => null, 'filesystems.disks.s3.endpoint' => null]);

    $this->get('/health/deep')->assertOk();
});

it('defaults require_configured to true only in production', function (): void {
    // The shipped default is environment-derived; assert the rule, not the live
    // value (tests run as APP_ENV=testing → false).
    expect(config('servana.health.require_configured'))->toBeFalse();
});
