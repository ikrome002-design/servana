<?php

declare(strict_types=1);

use App\Domain\Idempotency\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class)->group('idempotency');

/*
 | Pruning (Plan §24.4 retention; Phase R4). Bounded, safe, and never deletes an
 | active processing lock.
 */

it('prunes expired completed records but keeps unexpired ones', function (): void {
    IdempotencyKey::factory()->completed()->create(['expires_at' => now()->subHour()]);
    IdempotencyKey::factory()->completed()->create(['expires_at' => now()->addDay()]);

    Artisan::call('idempotency:prune');

    expect(IdempotencyKey::query()->count())->toBe(1);
});

it('never prunes an active processing lock even past expires_at', function (): void {
    IdempotencyKey::factory()->create([
        'state' => 'processing',
        'lock_expires_at' => now()->addMinutes(5), // active
        'expires_at' => now()->subHour(),          // technically expired
    ]);

    Artisan::call('idempotency:prune');

    expect(IdempotencyKey::query()->count())->toBe(1);
});

it('prunes an expired, abandoned processing row', function (): void {
    IdempotencyKey::factory()->expiredLock()->create(['expires_at' => now()->subHour()]);

    Artisan::call('idempotency:prune');

    expect(IdempotencyKey::query()->count())->toBe(0);
});

it('respects the batch bound', function (): void {
    IdempotencyKey::factory()->count(5)->completed()->create(['expires_at' => now()->subHour()]);

    Artisan::call('idempotency:prune', ['--batch' => 2]);

    expect(IdempotencyKey::query()->count())->toBe(3);
});
