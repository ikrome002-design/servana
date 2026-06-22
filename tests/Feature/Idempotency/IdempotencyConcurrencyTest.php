<?php

declare(strict_types=1);

use App\Domain\Idempotency\ClaimResult;
use App\Domain\Idempotency\IdempotencyStore;
use App\Domain\Idempotency\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | Concurrency (Plan §24.4 steps 3-4; Phase R4). The correctness boundary is
 | PostgreSQL — the UNIQUE (scope, key_hash) constraint and `SELECT ... FOR UPDATE`
 | — never process memory. The constraint is enforced by the database for ANY two
 | racers; these tests exercise it and the claim state machine deterministically.
 */

uses(RefreshDatabase::class)->group('idempotency', 'concurrency');

it('serializes two same-key claims to exactly one Claimed', function (): void {
    $store = app(IdempotencyStore::class);
    $scope = 'merchant:m:user:u';
    $key = hash('sha256', 'k1');
    $req = hash('sha256', 'r1');

    $first = $store->claim($scope, $key, $req, idempotencyMeta(), 30, 259200);
    $second = $store->claim($scope, $key, $req, idempotencyMeta(), 30, 259200);

    expect($first->result)->toBe(ClaimResult::Claimed)
        ->and($second->result)->toBe(ClaimResult::InProgress)
        ->and($second->retryAfterSeconds)->toBeGreaterThan(0)
        ->and(IdempotencyKey::query()->count())->toBe(1); // exactly one row/effect
});

it('relies on the PostgreSQL unique constraint to reject a duplicate claim', function (): void {
    $now = now();
    $row = fn (): array => [
        'ulid' => (string) Str::ulid(),
        'idempotency_scope' => 'merchant:x:user:y',
        'key_hash' => hash('sha256', 'race'),
        'route_name' => 't', 'http_method' => 'POST', 'request_hash' => hash('sha256', 'r'),
        'state' => 'processing',
        'locked_at' => $now, 'lock_expires_at' => $now->copy()->addSeconds(30),
        'expires_at' => $now->copy()->addHours(72),
        'created_at' => $now, 'updated_at' => $now,
    ];

    // Two ON CONFLICT DO NOTHING inserts of the same (scope, key_hash): the
    // PostgreSQL unique index lets exactly one win, regardless of caller.
    $first = DB::table('idempotency_keys')->insertOrIgnore($row());
    $second = DB::table('idempotency_keys')->insertOrIgnore($row());

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and(IdempotencyKey::query()->where('idempotency_scope', 'merchant:x:user:y')->count())->toBe(1);
});

it('replays after the claimed request completes (no re-execution)', function (): void {
    $store = app(IdempotencyStore::class);
    $scope = 'merchant:c:user:d';
    $key = hash('sha256', 'k2');
    $req = hash('sha256', 'r2');

    $claim = $store->claim($scope, $key, $req, idempotencyMeta(), 30, 259200);
    expect($claim->result)->toBe(ClaimResult::Claimed);

    $store->complete($claim->row, 200, ['content-type' => 'application/json'], ['ok' => true], 259200);

    $replay = $store->claim($scope, $key, $req, idempotencyMeta(), 30, 259200);

    expect($replay->result)->toBe(ClaimResult::Replay)
        ->and($replay->row->response_body_encrypted)->toBe(['ok' => true]);
});

it('conflicts when the same key arrives with a different request hash', function (): void {
    $store = app(IdempotencyStore::class);
    $scope = 'merchant:c:user:e';
    $key = hash('sha256', 'k3');

    $store->claim($scope, $key, hash('sha256', 'reqA'), idempotencyMeta(), 30, 259200);
    $conflict = $store->claim($scope, $key, hash('sha256', 'reqB'), idempotencyMeta(), 30, 259200);

    expect($conflict->result)->toBe(ClaimResult::ConflictDifferent);
});
