<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Mfa\RecoveryCodeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class)->group('auth', 'mfa', 'audit');

/*
 | MFA events land on the canonical R2 audit chain with the acting user as actor
 | and no secrets in context; the chain still verifies (Plan §18, §70).
 */

it('audits enrollment started and confirmed', function (): void {
    [$admin] = activeAdmin();

    $secret = $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/enroll')->json('data.secret');

    expect(AuditLog::query()->where('action', 'mfa.enrollment_started')->where('actor_id', $admin->id)->exists())->toBeTrue();

    $this->postJson('/api/v1/auth/mfa/confirm', ['code' => totpCode($secret)])->assertStatus(200);

    expect(AuditLog::query()->where('action', 'mfa.enrollment_confirmed')->where('actor_id', $admin->id)->exists())->toBeTrue();
});

it('audits challenge success and failure', function (): void {
    [$admin] = activeAdmin();
    [, $secret] = confirmedTotp($admin);

    $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/challenge', ['code' => '000000'])->assertStatus(422);
    expect(AuditLog::query()->where('action', 'mfa.challenge_failed')->exists())->toBeTrue();

    $this->postJson('/api/v1/auth/mfa/challenge', ['code' => totpCode($secret)])->assertStatus(200);
    $ok = AuditLog::query()->where('action', 'mfa.challenge_succeeded')->latest('id')->first();
    expect($ok)->not->toBeNull()->and($ok->severity)->toBe(AuditSeverity::Info);
});

it('audits recovery-code use', function (): void {
    [$admin] = activeAdmin();
    confirmedTotp($admin);
    $codes = app(RecoveryCodeManager::class)->regenerate($admin);

    $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/recovery-challenge', ['code' => $codes[0]])->assertStatus(200);

    expect(AuditLog::query()->where('action', 'mfa.recovery_code_used')->exists())->toBeTrue();
});

it('audits step-up denied and succeeded', function (): void {
    [$admin] = activeAdmin();

    $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/testing/step-up/refund_finalization')->assertStatus(403);
    expect(AuditLog::query()->where('action', 'mfa.step_up_denied')->exists())->toBeTrue();

    $this->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/testing/step-up/refund_finalization')->assertStatus(200);
    expect(AuditLog::query()->where('action', 'mfa.step_up_succeeded')->exists())->toBeTrue();
});

it('never stores secrets or codes in audit context and the chain still verifies', function (): void {
    [$admin] = activeAdmin();

    $secret = $this->statefulMfa()->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/enroll')->json('data.secret');
    $recoveryCodes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => totpCode($secret)])
        ->json('data.recovery_codes');

    foreach (AuditLog::query()->get() as $row) {
        $context = json_encode($row->context);
        expect($context)->not->toContain($secret);
        foreach ($recoveryCodes as $code) {
            expect($context)->not->toContain($code);
        }
    }

    expect(Artisan::call('audit:verify-chain'))->toBe(0);
});
