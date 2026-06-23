<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

uses()->group('infrastructure', 'health');

/*
 | Readiness failure paths (Plan §22.1, §79 R7; REM-OPS-001). Each REQUIRED
 | production dependency failing forces a 503, with a safe `unhealthy` body. An
 | OPTIONAL dependency failing only degrades (200). No secrets/exception detail.
 */

it('returns 503 when the database is unavailable', function (): void {
    config(['database.connections.pgsql.password' => '__wrong_password__']);
    DB::purge('pgsql');

    $this->get('/health/deep')
        ->assertStatus(503)
        ->assertJsonPath('status', 'unhealthy')
        ->assertJsonPath('checks.database.status', 'error');
});

it('returns 503 when Redis is unavailable', function (): void {
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('redis down'));

    $this->get('/health/deep')
        ->assertStatus(503)
        ->assertJsonPath('status', 'unhealthy')
        ->assertJsonPath('checks.redis.status', 'error');
});

it('returns 503 when the cache store is unavailable', function (): void {
    Cache::shouldReceive('put')->andThrow(new RuntimeException('cache down'));

    $this->get('/health/deep')
        ->assertStatus(503)
        ->assertJsonPath('status', 'unhealthy')
        ->assertJsonPath('checks.cache.status', 'error');
});

it('returns 503 when a configured S3 object store is unreachable', function (): void {
    config([
        'filesystems.disks.s3.bucket' => 'servana',
        'filesystems.disks.s3.endpoint' => 'http://127.0.0.1:1',
    ]);

    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->andThrow(new RuntimeException('s3 down'));
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

    $this->get('/health/deep')
        ->assertStatus(503)
        ->assertJsonPath('status', 'unhealthy')
        ->assertJsonPath('checks.s3.status', 'error');
});

it('returns 200 when only an optional dependency fails', function (): void {
    // Meilisearch (Phase 22) is optional — its failure degrades but never 503s.
    config(['services.meilisearch.host' => 'http://127.0.0.1:1']);

    $this->get('/health/deep')
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.meilisearch.status', 'error');
});
