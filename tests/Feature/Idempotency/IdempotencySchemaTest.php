<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('idempotency');

/*
 | idempotency_keys schema (Plan §13.5 corrected, §24.4; Phase R4). Proves the
 | corrected columns, the unique scope+key boundary, the recovery/prune indexes,
 | and the state CHECK exist as specified.
 */

it('creates idempotency_keys with the corrected columns', function (): void {
    expect(Schema::hasTable('idempotency_keys'))->toBeTrue();

    foreach ([
        'id', 'ulid', 'idempotency_scope', 'key_hash', 'actor_user_id', 'merchant_id',
        'branch_id', 'route_name', 'http_method', 'request_content_type', 'request_hash',
        'state', 'response_status', 'response_headers', 'response_body_encrypted',
        'locked_at', 'lock_expires_at', 'completed_at', 'failed_at', 'last_error_code',
        'expires_at', 'created_at', 'updated_at',
    ] as $column) {
        expect(Schema::hasColumn('idempotency_keys', $column))->toBeTrue("missing {$column}");
    }
});

it('enforces UNIQUE (idempotency_scope, key_hash)', function (): void {
    $indexes = collect(DB::select('
        SELECT indexname FROM pg_indexes WHERE tablename = ?
    ', ['idempotency_keys']))->pluck('indexname');

    expect($indexes)->toContain('idempotency_keys_idempotency_scope_key_hash_unique')
        ->and($indexes)->toContain('idempotency_keys_state_lock_expires_at_index')
        ->and($indexes)->toContain('idempotency_keys_expires_at_index');
});

it('rejects a state outside the CHECK', function (): void {
    expect(fn () => DB::table('idempotency_keys')->insert([
        'ulid' => (string) Str::ulid(),
        'idempotency_scope' => 'platform:user:x',
        'key_hash' => str_repeat('a', 64),
        'route_name' => 'r', 'http_method' => 'POST', 'request_hash' => str_repeat('b', 64),
        'state' => 'bogus',
        'locked_at' => now(), 'lock_expires_at' => now(), 'expires_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a duplicate (scope, key_hash)', function (): void {
    $row = [
        'idempotency_scope' => 'merchant:m:user:u',
        'key_hash' => str_repeat('c', 64),
        'route_name' => 'r', 'http_method' => 'POST', 'request_hash' => str_repeat('d', 64),
        'state' => 'processing',
        'locked_at' => now(), 'lock_expires_at' => now()->addSeconds(30), 'expires_at' => now()->addHours(72),
        'created_at' => now(), 'updated_at' => now(),
    ];

    DB::table('idempotency_keys')->insert(['ulid' => (string) Str::ulid()] + $row);

    expect(fn () => DB::table('idempotency_keys')->insert(['ulid' => (string) Str::ulid()] + $row))
        ->toThrow(QueryException::class);
});
