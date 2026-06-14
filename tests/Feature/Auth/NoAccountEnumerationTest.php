<?php

declare(strict_types=1);

use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('auth');

it('returns identical responses whether or not the email exists (request)', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'real@salon.co.ke']);

    $existing = $this->postJson('/api/v1/auth/magic-link', ['email' => 'real@salon.co.ke']);
    $missing = $this->postJson('/api/v1/auth/magic-link', ['email' => 'fake@salon.co.ke']);

    expect($existing->status())->toBe($missing->status())->toBe(202);
    expect($existing->json())->toEqual($missing->json());
});

it('returns identical failures for inactive-user vs nonexistent-token (verify)', function (): void {
    // Case A: a valid token, but the user is suspended at consume time.
    $user = User::factory()->create(['email' => 'real@salon.co.ke']);
    $raw = app(MagicLinkTokenService::class)->issue('real@salon.co.ke');
    $user->status = User::STATUS_SUSPENDED; // not mass-assignable (security)
    $user->save();
    $ineligible = $this->postJson('/api/v1/auth/magic-link/verify', ['token' => $raw]);

    // Case B: a token that never existed.
    $bogus = $this->postJson('/api/v1/auth/magic-link/verify', ['token' => 'no-such-token']);

    expect($ineligible->status())->toBe($bogus->status())->toBe(422);
    expect($ineligible->json())->toEqual($bogus->json());
});
