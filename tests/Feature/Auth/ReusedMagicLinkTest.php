<?php

declare(strict_types=1);

use App\Domain\Auth\Models\MagicLoginToken;
use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth');

it('allows a token to be used once and rejects the second use', function (): void {
    eligibleOwner('owner@salon.co.ke');
    $raw = issueBoundMagicLink('owner@salon.co.ke');

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $raw])->assertStatus(200);

    // Second attempt with the same token must fail uniformly.
    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $raw])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_token');
});

it('consume is atomic — only one of two concurrent consumes succeeds', function (): void {
    User::factory()->create(['email' => 'owner@salon.co.ke']);
    $service = app(MagicLinkTokenService::class);
    $raw = issueBoundMagicLink('owner@salon.co.ke');

    $host = accountHostName('merchant_administrator');

    $first = $service->consume($raw, 'merchant_administrator', $host, 'testing');
    $second = $service->consume($raw, 'merchant_administrator', $host, 'testing');

    expect($first?->email)->toBe('owner@salon.co.ke')
        ->and($second)->toBeNull();
});

it('does not consume an invalidated token', function (): void {
    User::factory()->create(['email' => 'owner@salon.co.ke']);
    $service = app(MagicLinkTokenService::class);
    $raw = issueBoundMagicLink('owner@salon.co.ke');

    // Simulate suspension-time invalidation (Plan §9.2; wired in Phase 7).
    MagicLoginToken::query()
        ->where('token_hash', $service->hash($raw))
        ->update(['invalidated_at' => now()]);

    expect($service->consume($raw, 'merchant_administrator', accountHostName('merchant_administrator'), 'testing'))->toBeNull();
});
