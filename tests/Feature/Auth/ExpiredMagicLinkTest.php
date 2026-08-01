<?php

declare(strict_types=1);

use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class)->group('auth');

it('rejects an expired token with a uniform 422 and does not authenticate', function (): void {
    User::factory()->create(['email' => 'owner@salon.co.ke']);
    $raw = issueBoundMagicLink('owner@salon.co.ke');

    // Move past the 15-minute window.
    Carbon::setTestNow(now()->addMinutes(MagicLinkTokenService::EXPIRY_MINUTES + 1));

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $raw])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_token');

    $this->assertGuest();

    Carbon::setTestNow();
});

it('rejects a token whose signature does not match any row', function (): void {
    User::factory()->create(['email' => 'owner@salon.co.ke']);

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => 'totally-made-up-token'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_token');

    $this->assertGuest();
});
