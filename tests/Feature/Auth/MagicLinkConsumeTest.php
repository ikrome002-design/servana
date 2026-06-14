<?php

declare(strict_types=1);

use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth');

it('consumes a valid token, logs in, and returns the bootstrap payload', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'owner@salon.co.ke']);
    $raw = app(MagicLinkTokenService::class)->issue('owner@salon.co.ke');

    $response = postStateful('/api/v1/auth/magic-link/verify', ['token' => $raw])
        ->assertStatus(200)
        ->assertJsonPath('data.email', 'owner@salon.co.ke')
        ->assertJsonStructure(['data' => ['id', 'email', 'name', 'status', 'memberships', 'permissions']]);

    // Public identifier is the ULID, never the bigint PK (A5).
    expect($response->json('data.id'))->toBe($user->ulid);

    $this->assertAuthenticatedAs($user->fresh());
});

it('sets email_verified_at on first login and stamps last_login_at', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'first@salon.co.ke']);
    expect($user->email_verified_at)->toBeNull();

    $raw = app(MagicLinkTokenService::class)->issue('first@salon.co.ke');
    postStateful('/api/v1/auth/magic-link/verify', ['token' => $raw])->assertStatus(200);

    $fresh = $user->fresh();
    expect($fresh->email_verified_at)->not->toBeNull()
        ->and($fresh->last_login_at)->not->toBeNull();
});

it('does not expose the raw token or bigint id in the response', function (): void {
    User::factory()->create(['email' => 'safe@salon.co.ke']);
    $raw = app(MagicLinkTokenService::class)->issue('safe@salon.co.ke');

    $response = postStateful('/api/v1/auth/magic-link/verify', ['token' => $raw])->assertStatus(200);

    expect($response->getContent())->not->toContain($raw);
    // The numeric PK must not leak.
    expect($response->json('data.id'))->not->toBeNumeric();
});

it('rejects a structurally missing token via validation', function (): void {
    $this->postJson('/api/v1/auth/magic-link/verify', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});
