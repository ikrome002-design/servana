<?php

declare(strict_types=1);

use App\Domain\Idempotency\ClaimResult;
use App\Domain\Idempotency\IdempotencyStore;
use App\Domain\Idempotency\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('idempotency');

/*
 | Crash recovery (Plan §24.4 step 4; Phase R4). An expired processing lock is
 | recoverable under SELECT ... FOR UPDATE; an active lock is never stolen; only
 | one recoverer wins (the next claim sees an active lock).
 */

it('recovers an expired processing lock', function (): void {
    $store = app(IdempotencyStore::class);
    $scope = 'merchant:e:user:f';
    $key = hash('sha256', 'k');
    $req = hash('sha256', 'r');

    IdempotencyKey::factory()->expiredLock()->create([
        'idempotency_scope' => $scope, 'key_hash' => $key, 'request_hash' => $req,
    ]);

    $recovered = $store->claim($scope, $key, $req, idempotencyMeta(), 30, 259200);

    expect($recovered->result)->toBe(ClaimResult::Claimed)
        ->and($recovered->row->lock_expires_at->isFuture())->toBeTrue();

    // The lock is now active again — a second worker cannot also reclaim it.
    $second = $store->claim($scope, $key, $req, idempotencyMeta(), 30, 259200);
    expect($second->result)->toBe(ClaimResult::InProgress)
        ->and(IdempotencyKey::query()->count())->toBe(1);
});

it('never steals an active processing lock', function (): void {
    $store = app(IdempotencyStore::class);
    $scope = 'merchant:g:user:h';
    $key = hash('sha256', 'k');
    $req = hash('sha256', 'r');

    IdempotencyKey::factory()->create([
        'idempotency_scope' => $scope, 'key_hash' => $key, 'request_hash' => $req,
        'state' => 'processing', 'lock_expires_at' => now()->addMinutes(5),
    ]);

    expect($store->claim($scope, $key, $req, idempotencyMeta(), 30, 259200)->result)
        ->toBe(ClaimResult::InProgress);
});

it('retries a failed (server-error) row by reclaiming it', function (): void {
    $store = app(IdempotencyStore::class);
    $scope = 'merchant:i:user:j';
    $key = hash('sha256', 'k');
    $req = hash('sha256', 'r');

    IdempotencyKey::factory()->failed()->create([
        'idempotency_scope' => $scope, 'key_hash' => $key, 'request_hash' => $req,
    ]);

    $retry = $store->claim($scope, $key, $req, idempotencyMeta(), 30, 259200);

    expect($retry->result)->toBe(ClaimResult::Claimed)
        ->and($retry->row->last_error_code)->toBeNull()
        ->and($retry->row->lock_expires_at->isFuture())->toBeTrue();
});
