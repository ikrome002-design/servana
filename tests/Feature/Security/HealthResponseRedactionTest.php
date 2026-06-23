<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

uses()->group('infrastructure', 'health', 'security');

/*
 | Health-probe redaction (Plan §22.1, §24, §79 R7). A failing readiness probe
 | reports safe dependency names and statuses only — never a DSN, host, bucket,
 | credential, SQL state or raw exception detail.
 */

it('never leaks DB credentials or SQL detail when the database fails', function (): void {
    // Unique sentinel so the assertion cannot collide with the service name.
    $sentinel = '__r7_unique_db_password_should_not_leak__';
    config(['database.connections.pgsql.password' => $sentinel]);
    DB::purge('pgsql');

    $body = (string) $this->get('/health/deep')->getContent();

    expect($body)
        ->not->toContain($sentinel)
        ->not->toContain('secret')
        ->not->toContain('password')
        ->not->toContain('Bearer ')
        ->not->toContain('SQLSTATE')
        ->not->toContain('pgsql:');
});

it('never leaks the Redis host when Redis fails', function (): void {
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('connection to 10.1.2.3:6379 refused'));

    $body = (string) $this->get('/health/deep')->getContent();

    expect($body)
        ->not->toContain('10.1.2.3')
        ->not->toContain('refused')
        ->and(json_decode($body, true)['checks']['redis']['status'])->toBe('error');
});

it('never leaks the S3 bucket, endpoint or credentials when S3 fails', function (): void {
    config([
        'filesystems.disks.s3.bucket' => 'servana-secret-bucket',
        'filesystems.disks.s3.endpoint' => 'https://s3.internal.example',
        'filesystems.disks.s3.key' => 'AKIA_SECRET_KEY',
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->andThrow(new RuntimeException('AccessDenied for AKIA_SECRET_KEY'));
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

    $body = (string) $this->get('/health/deep')->getContent();

    expect($body)
        ->not->toContain('servana-secret-bucket')
        ->not->toContain('s3.internal.example')
        ->not->toContain('AKIA_SECRET_KEY')
        ->not->toContain('AccessDenied');
});
