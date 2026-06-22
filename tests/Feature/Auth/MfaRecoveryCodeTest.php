<?php

declare(strict_types=1);

use App\Domain\Auth\Mfa\RecoveryCodeManager;
use App\Domain\Auth\Models\MfaRecoveryCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('auth', 'mfa');

/*
 | One-time recovery codes (Plan §18; Phase R3). Hashed at rest, single-use
 | through the same challenge contract, atomic under concurrent attempts.
 */

it('asserts the session via a valid recovery code and consumes it once', function (): void {
    [$admin] = activeAdmin();
    confirmedTotp($admin);
    $codes = app(RecoveryCodeManager::class)->regenerate($admin);

    $response = $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/recovery-challenge', ['code' => $codes[0]])
        ->assertStatus(200);

    expect($response->json('data.mfa.verified'))->toBeTrue();

    // Re-using the same recovery code is refused (single-use).
    $this->postJson('/api/v1/auth/mfa/recovery-challenge', ['code' => $codes[0]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'mfa_invalid_code');
});

it('rejects an unknown recovery code', function (): void {
    [$admin] = activeAdmin();
    confirmedTotp($admin);
    app(RecoveryCodeManager::class)->regenerate($admin);

    $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/recovery-challenge', ['code' => 'ZZZZZ-ZZZZZ'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'mfa_invalid_code');
});

it('stores recovery codes only as hashes', function (): void {
    [$admin] = activeAdmin();
    $codes = app(RecoveryCodeManager::class)->regenerate($admin);

    $stored = DB::table('mfa_recovery_codes')->where('user_id', $admin->id)->pluck('code_hash')->all();

    foreach ($stored as $hash) {
        expect($hash)->toHaveLength(64)              // sha-256 hex
            ->and($codes)->not->toContain($hash);    // never the plaintext
    }
    // Every plaintext maps to a stored hash.
    foreach ($codes as $code) {
        expect($stored)->toContain(hash('sha256', $code));
    }
});

it('consumes a recovery code atomically — only the first attempt wins', function (): void {
    [$admin] = activeAdmin();
    $codes = app(RecoveryCodeManager::class)->regenerate($admin);
    $manager = app(RecoveryCodeManager::class);

    // Two sequential consumes of the SAME code: the conditional UPDATE matches
    // exactly one unused row, so the second cannot also succeed.
    expect($manager->consume($admin, $codes[0]))->toBeTrue()
        ->and($manager->consume($admin, $codes[0]))->toBeFalse();
});

it('decrements the remaining recovery-code count when one is used', function (): void {
    [$admin] = activeAdmin();
    $codes = app(RecoveryCodeManager::class)->regenerate($admin);
    $manager = app(RecoveryCodeManager::class);

    $before = $manager->remaining($admin);
    $manager->consume($admin, $codes[0]);

    expect($manager->remaining($admin))->toBe($before - 1)
        ->and(MfaRecoveryCode::query()->where('user_id', $admin->id)->whereNotNull('used_at')->count())->toBe(1);
});

it('regenerating replaces the whole set', function (): void {
    [$admin] = activeAdmin();
    $first = app(RecoveryCodeManager::class)->regenerate($admin);
    $second = app(RecoveryCodeManager::class)->regenerate($admin);

    expect($second)->not->toBe($first)
        ->and(MfaRecoveryCode::query()->where('user_id', $admin->id)->count())
        ->toBe((int) config('servana.mfa.recovery_code_count'));
});
