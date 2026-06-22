<?php

declare(strict_types=1);

use App\Domain\Auth\Mfa\StepUpAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'mfa');

/*
 | Reusable fresh step-up (Plan §18, §9.4 step 13; Phase R3). The test-only
 | harness exercises RequireFreshMfa for EVERY designated business classification
 | without any fake business logic; the live recovery-codes route proves it on a
 | real route too.
 */

it('denies every designated action without an MFA assertion', function (): void {
    [$admin] = activeAdmin();

    foreach (StepUpAction::businessActions() as $action) {
        $this->statefulMfa()->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/testing/step-up/{$action->value}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'step_up_required');
    }
});

it('denies every designated action with a stale assertion', function (): void {
    [$admin] = activeAdmin();
    $stale = now()->subMinutes((int) config('servana.mfa.step_up_window_minutes') + 1)->getTimestamp();

    foreach (StepUpAction::businessActions() as $action) {
        $this->statefulMfa($stale)->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/testing/step-up/{$action->value}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'step_up_required');
    }
});

it('allows every designated action with a fresh assertion', function (): void {
    [$admin] = activeAdmin();
    $fresh = now()->getTimestamp();

    foreach (StepUpAction::businessActions() as $action) {
        $this->statefulMfa($fresh)->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/testing/step-up/{$action->value}")
            ->assertStatus(200)
            ->assertJsonPath('action', $action->value);
    }
});

it('requires fresh step-up to regenerate recovery codes (a live route)', function (): void {
    [$admin] = activeAdmin();
    confirmedTotp($admin);

    $stale = now()->subMinutes((int) config('servana.mfa.step_up_window_minutes') + 1)->getTimestamp();

    // Asserted (so the privileged gate passes) but stale → step-up denied.
    $this->statefulMfa($stale)->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/recovery-codes')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'step_up_required');

    // Fresh → allowed; returns a new set of codes.
    $this->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/auth/mfa/recovery-codes')
        ->assertStatus(200)
        ->assertJsonCount((int) config('servana.mfa.recovery_code_count'), 'data.recovery_codes');
});

it('fails loud on an unregistered step-up classification', function (): void {
    expect(fn () => StepUpAction::from('not_a_real_action'))->toThrow(ValueError::class);
});
