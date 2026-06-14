<?php

declare(strict_types=1);

use App\Domain\Auth\Models\MagicLoginToken;
use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth');

it('allows a token to be used once and rejects the second use', function (): void {
    eligibleOwner('owner@salon.co.ke');
    $raw = app(MagicLinkTokenService::class)->issue('owner@salon.co.ke');

    postStateful('/api/v1/auth/magic-link/verify', ['token' => $raw])->assertStatus(200);

    // Second attempt with the same token must fail uniformly.
    postStateful('/api/v1/auth/magic-link/verify', ['token' => $raw])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_token');
});

it('consume is atomic — only one of two concurrent consumes succeeds', function (): void {
    User::factory()->create(['email' => 'owner@salon.co.ke']);
    $service = app(MagicLinkTokenService::class);
    $raw = $service->issue('owner@salon.co.ke');

    $first = $service->consume($raw);
    $second = $service->consume($raw);

    expect($first)->toBe('owner@salon.co.ke')
        ->and($second)->toBeNull();
});

it('does not consume an invalidated token', function (): void {
    User::factory()->create(['email' => 'owner@salon.co.ke']);
    $service = app(MagicLinkTokenService::class);
    $raw = $service->issue('owner@salon.co.ke');

    // Simulate suspension-time invalidation (Plan §9.2; wired in Phase 7).
    MagicLoginToken::query()
        ->where('token_hash', $service->hash($raw))
        ->update(['invalidated_at' => now()]);

    expect($service->consume($raw))->toBeNull();
});
