<?php

declare(strict_types=1);

use App\Domain\Auth\Mfa\RecoveryCodeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('security', 'mfa');

/*
 | Secret/code redaction (Plan §9 rule 13; Phase R3). The TOTP secret and
 | recovery codes never appear in plaintext at rest, in the audit trail, or in
 | the bootstrap/status payloads.
 */

it('stores only ciphertext for the totp secret', function (): void {
    [$admin] = activeAdmin();

    $secret = $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/enroll')->json('data.secret');

    $stored = DB::table('mfa_credentials')->where('user_id', $admin->id)->value('secret_encrypted');

    expect($stored)->not->toBeNull()
        ->and($stored)->not->toContain($secret);
});

it('stores only hashes for recovery codes', function (): void {
    [$admin] = activeAdmin();
    $codes = app(RecoveryCodeManager::class)->regenerate($admin);

    $stored = DB::table('mfa_recovery_codes')->where('user_id', $admin->id)->pluck('code_hash')->all();

    foreach ($stored as $hash) {
        expect($hash)->toHaveLength(64)
            ->and($codes)->not->toContain($hash);
    }
});

it('keeps the secret and recovery codes out of the audit trail', function (): void {
    [$admin] = activeAdmin();

    $secret = $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/enroll')->json('data.secret');
    $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => totpCode($secret)])
        ->json('data.recovery_codes');

    $rawContexts = DB::table('audit_logs')->pluck('context')->implode(' ');

    expect($rawContexts)->not->toContain($secret);
    foreach ($codes as $code) {
        expect($rawContexts)->not->toContain($code);
    }
});

it('never exposes the secret or hashes in the bootstrap or status payloads', function (): void {
    [$admin] = activeAdmin();
    [, $secret] = confirmedTotp($admin);

    $me = $this->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/me')->json('data.mfa');
    $status = $this->getJson('/api/v1/auth/mfa')->json('data.mfa');

    foreach ([$me, $status] as $payload) {
        expect($payload)->not->toHaveKey('secret')
            ->and($payload)->not->toHaveKey('secret_encrypted')
            ->and($payload)->not->toHaveKey('otpauth_uri')
            ->and(json_encode($payload))->not->toContain($secret);
    }
});
