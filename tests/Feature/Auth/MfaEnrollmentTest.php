<?php

declare(strict_types=1);

use App\Domain\Auth\Models\MfaCredential;
use App\Domain\Auth\Models\MfaRecoveryCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('auth', 'mfa');

/*
 | TOTP enrollment + confirmation (Plan §18; Phase R3, REM-MFA-001). Uses a
 | merchant_admin (a mandatory-MFA role) and the real HTTP flow; statefulMfa()
 | opts out of the test harness's auto MFA session so we drive enrollment.
 */

it('starts enrollment returning a secret and an otpauth uri', function (): void {
    [$admin] = activeAdmin();

    $response = $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/enroll')
        ->assertStatus(200);

    expect($response->json('data.secret'))->toBeString()->not->toBeEmpty()
        ->and($response->json('data.otpauth_uri'))->toContain('otpauth://totp/')
        ->and($response->json('data.mfa.enrollment_required'))->toBeTrue();

    $credential = MfaCredential::query()->where('user_id', $admin->id)->first();
    expect($credential)->not->toBeNull()
        ->and($credential->confirmed_at)->toBeNull();
});

it('confirms enrollment with a valid totp and returns recovery codes once', function (): void {
    [$admin] = activeAdmin();

    $secret = $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/enroll')->json('data.secret');

    $response = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => totpCode($secret)])
        ->assertStatus(200);

    expect($response->json('data.recovery_codes'))->toHaveCount(config('servana.mfa.recovery_code_count'))
        ->and($response->json('data.mfa.confirmed'))->toBeTrue()
        ->and($response->json('data.mfa.verified'))->toBeTrue();

    expect(MfaCredential::query()->where('user_id', $admin->id)->first()->confirmed_at)->not->toBeNull()
        ->and(MfaRecoveryCode::query()->where('user_id', $admin->id)->count())
        ->toBe((int) config('servana.mfa.recovery_code_count'));
});

it('rejects an invalid confirmation code and leaves the credential unconfirmed', function (): void {
    [$admin] = activeAdmin();

    $this->statefulMfa()->actingAs($admin, 'sanctum')->postJson('/api/v1/auth/mfa/enroll');

    $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'mfa_invalid_code');

    expect(MfaCredential::query()->where('user_id', $admin->id)->first()->confirmed_at)->toBeNull();
});

it('encrypts the totp secret at rest', function (): void {
    [$admin] = activeAdmin();

    $secret = $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/enroll')->json('data.secret');

    $stored = DB::table('mfa_credentials')->where('user_id', $admin->id)->value('secret_encrypted');

    expect($stored)->not->toBe($secret)
        ->and($stored)->not->toContain($secret)
        // The model decrypts transparently back to the same plaintext.
        ->and(MfaCredential::query()->where('user_id', $admin->id)->first()->secret_encrypted)->toBe($secret);
});

it('refuses to start enrollment when already confirmed', function (): void {
    [$admin] = activeAdmin();
    confirmedTotp($admin);

    $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/enroll')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'mfa_invalid_state');
});

it('safely rotates an abandoned unconfirmed enrollment', function (): void {
    [$admin] = activeAdmin();

    $first = $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/enroll')->json('data.secret');
    $second = $this->postJson('/api/v1/auth/mfa/enroll')->json('data.secret');

    expect($second)->not->toBe($first)
        ->and(MfaCredential::query()->where('user_id', $admin->id)->count())->toBe(1);
});

it('generates no recovery codes before confirmation', function (): void {
    [$admin] = activeAdmin();

    $this->statefulMfa()->actingAs($admin, 'sanctum')->postJson('/api/v1/auth/mfa/enroll');

    expect(MfaRecoveryCode::query()->where('user_id', $admin->id)->count())->toBe(0);
});

it('requires authentication to enroll', function (): void {
    $this->postJson('/api/v1/auth/mfa/enroll')->assertStatus(401);
});
