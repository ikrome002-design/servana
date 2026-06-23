<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

uses()->group('infrastructure', 'health');

/*
 | Liveness probe (Plan §22.1, §79 R7). `GET /health` reports only that the PHP
 | process can serve requests — it must never touch a backing dependency and must
 | stay 200 even when every dependency is down. No versions/hosts/secrets.
 */

it('returns a small stable 200 liveness body', function (): void {
    $this->get('/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('service', 'servana')
        ->assertJsonStructure(['status', 'service', 'timestamp'])
        // Liveness must NOT report dependency checks (that is readiness).
        ->assertJsonMissingPath('checks');
});

it('remains 200 when backing dependencies are unavailable', function (): void {
    // Break the database connection and the Redis manager. Liveness must not care.
    config(['database.connections.pgsql.password' => '__wrong__']);
    DB::purge('pgsql');
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('redis down'));

    $this->get('/health')->assertOk()->assertJsonPath('status', 'ok');
});

it('does not leak versions, hosts or credentials in the liveness body', function (): void {
    $body = (string) $this->get('/health')->getContent();

    expect($body)
        ->not->toContain('password')
        ->not->toContain('secret')
        ->not->toContain('127.0.0.1')
        ->not->toContain('redis')
        ->not->toContain('pgsql');
});
