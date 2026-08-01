<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth');

it('consumes a valid token, logs in, and returns the bootstrap payload', function (): void {
    $user = eligibleOwner('owner@salon.co.ke');
    $user->email_verified_at = null; // first login verifies it
    $user->save();

    $raw = issueBoundMagicLink('owner@salon.co.ke');

    $response = postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $raw])
        ->assertStatus(200)
        ->assertJsonPath('data.user.email', 'owner@salon.co.ke')
        ->assertJsonStructure(['data' => [
            'user' => ['id', 'email', 'name', 'status'],
            'merchant' => ['id', 'name', 'status'],
            'membership' => ['role', 'status'],
            'setup' => ['required', 'current_step'],
            'permissions',
        ]]);

    // Public identifier is the ULID, never the bigint PK (A5).
    expect($response->json('data.user.id'))->toBe($user->ulid)
        ->and($response->json('data.membership.role'))->toBe('merchant_admin');

    $this->assertAuthenticatedAs($user->fresh());
});

it('sets email_verified_at on first login and stamps last_login_at', function (): void {
    $user = eligibleOwner('first@salon.co.ke');
    $user->email_verified_at = null;
    $user->save();
    expect($user->fresh()->email_verified_at)->toBeNull();

    $raw = issueBoundMagicLink('first@salon.co.ke');
    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $raw])->assertStatus(200);

    $fresh = $user->fresh();
    expect($fresh->email_verified_at)->not->toBeNull()
        ->and($fresh->last_login_at)->not->toBeNull();
});

it('does not expose the raw token or bigint id in the response', function (): void {
    eligibleOwner('safe@salon.co.ke');
    $raw = issueBoundMagicLink('safe@salon.co.ke');

    $response = postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $raw])->assertStatus(200);

    expect($response->getContent())->not->toContain($raw);
    // The numeric PK must not leak.
    expect($response->json('data.user.id'))->not->toBeNumeric();
});

it('rejects a structurally missing token via validation', function (): void {
    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});
