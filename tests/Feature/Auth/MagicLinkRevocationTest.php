<?php

declare(strict_types=1);

use App\Domain\Auth\Actions\RequestMagicLink;
use App\Domain\Auth\Models\MagicLoginToken;
use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Services\StaffLifecycleService;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('auth', 'security');

/*
 | Magic-Link revocation (Plan §79 R6, §9.1/§9.2). A new link supersedes prior
 | unconsumed links; logout and suspension invalidate unconsumed links; an
 | invalidated link returns the uniform 422; the raw token is SHA-256-only at
 | rest. Enumeration resistance (uniform responses) is unchanged.
 */

it('invalidates the previous unconsumed link when a new one is requested', function (): void {
    Notification::fake();
    $user = eligibleOwner('rotate@salon.co.ke');
    $tokens = app(MagicLinkTokenService::class);

    // First link minted directly.
    $raw1 = issueBoundMagicLink('rotate@salon.co.ke');
    expect(MagicLoginToken::query()->where('email', 'rotate@salon.co.ke')->whereNull('invalidated_at')->count())->toBe(1);

    // Requesting a fresh link supersedes the first.
    app(RequestMagicLink::class)->handle('rotate@salon.co.ke');

    // The first token can no longer be consumed; exactly one usable link remains.
    expect($tokens->consume($raw1))->toBeNull()
        ->and(MagicLoginToken::query()->where('email', 'rotate@salon.co.ke')->whereNull('invalidated_at')->whereNull('consumed_at')->count())->toBe(1);
});

it('invalidates unconsumed links on logout', function (): void {
    $user = eligibleOwner('logout@salon.co.ke');
    $tokens = app(MagicLinkTokenService::class);
    $raw = issueBoundMagicLink('logout@salon.co.ke');

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($tokens->consume($raw))->toBeNull();
});

it('invalidates unconsumed links on suspension', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$staffUser, $membership] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);
    $tokens = app(MagicLinkTokenService::class);
    $raw = issueBoundMagicLink($staffUser->email);

    app(StaffLifecycleService::class)->suspend($membership);

    expect($tokens->consume($raw))->toBeNull();
});

it('returns the uniform 422 when verifying an invalidated link', function (): void {
    eligibleOwner('uniform@salon.co.ke');
    $tokens = app(MagicLinkTokenService::class);
    $raw = issueBoundMagicLink('uniform@salon.co.ke');
    $tokens->invalidateUnconsumedForEmail('uniform@salon.co.ke');

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $raw])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_or_expired_token');
});

it('stores only the SHA-256 hash of a Magic Link, never the raw token', function (): void {
    $tokens = app(MagicLinkTokenService::class);
    $raw = issueBoundMagicLink('hash@salon.co.ke');

    $row = MagicLoginToken::query()->where('email', 'hash@salon.co.ke')->firstOrFail();
    expect($row->token_hash)->toBe(hash('sha256', $raw))
        ->and($row->token_hash)->not->toBe($raw)
        ->and($row->getAttributes())->not->toHaveKey('token');
});
