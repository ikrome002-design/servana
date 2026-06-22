<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'mfa');

/*
 | TOTP session challenge (Plan §18; Phase R3). A confirmed credential must be
 | asserted once per session; replays are rejected; attempts are rate-limited.
 */

it('asserts the session on a valid totp challenge', function (): void {
    [$admin] = activeAdmin();
    [, $secret] = confirmedTotp($admin);

    $response = $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/challenge', ['code' => totpCode($secret)])
        ->assertStatus(200);

    expect($response->json('data.mfa.verified'))->toBeTrue();

    // A privileged route is now reachable in the same (asserted) session.
    $this->getJson('/api/v1/testing/privileged-probe')->assertStatus(200);
});

it('rejects an invalid totp code', function (): void {
    [$admin] = activeAdmin();
    confirmedTotp($admin);

    $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/challenge', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'mfa_invalid_code');
});

it('rejects a replayed totp code', function (): void {
    [$admin] = activeAdmin();
    [, $secret] = confirmedTotp($admin);
    $code = totpCode($secret);

    $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/challenge', ['code' => $code])
        ->assertStatus(200);

    // Same code in the same time-step is now refused (replay prevention).
    $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'mfa_invalid_code');
});

it('rejects a challenge when no credential is confirmed', function (): void {
    [$admin] = activeAdmin(); // no MFA credential

    $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/challenge', ['code' => '123456'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'mfa_invalid_state');
});

it('rate-limits repeated challenge attempts', function (): void {
    [$admin] = activeAdmin();
    confirmedTotp($admin);

    $this->statefulMfa()->actingAs($admin, 'sanctum');

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => '000000'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/mfa/challenge', ['code' => '000000'])->assertStatus(429);
});
