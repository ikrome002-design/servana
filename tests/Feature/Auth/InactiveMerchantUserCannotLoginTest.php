<?php

declare(strict_types=1);

use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('auth');

/*
 | Phase 5 enforces user-level eligibility (Scope §2.3 checks 1, 3, 5). The
 | merchant-membership / role / branch checks (2, 4, 6) are deferred to Phase 6/7
 | and covered there; these tests prove the enforceable cases deny safely.
 */

it('sends no link to a deactivated user', function (): void {
    Notification::fake();
    User::factory()->deactivated()->create(['email' => 'gone@salon.co.ke']);

    $this->postJson('/api/v1/auth/magic-link', ['email' => 'gone@salon.co.ke'])
        ->assertStatus(202);

    Notification::assertNothingSent();
});

it('rejects consume when the user was suspended after the link was issued', function (): void {
    $user = User::factory()->create(['email' => 'owner@salon.co.ke']);
    $raw = app(MagicLinkTokenService::class)->issue('owner@salon.co.ke');

    // Status changes between issue and consume — re-check at consume must deny.
    // status is not mass-assignable (security), so set it directly.
    $user->status = User::STATUS_SUSPENDED;
    $user->save();

    $this->postJson('/api/v1/auth/magic-link/verify', ['token' => $raw])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_token');

    $this->assertGuest();
});
