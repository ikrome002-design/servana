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
 |
 | Phase UI-03 made `consume()` HOST-BOUND (ADR-019): it now takes the account, host and
 | environment the token is presented on, and verifies them inside the same atomic update as the
 | single-use predicate. These call sites were left on the Phase 5 one-argument shape, so they
 | raised ArgumentCountError rather than asserting anything. Each now names the account the token
 | was actually issued for — which is also what makes "revoked" mean revoked here, rather than
 | merely "presented on the wrong host".
 */

/** Consume on the host the token was bound to, so only revocation can make it fail. */
function consumeOnBoundHost(
    MagicLinkTokenService $tokens,
    string $rawToken,
    string $accountKey = 'merchant_administrator',
): ?MagicLoginToken {
    return $tokens->consume($rawToken, $accountKey, accountHostName($accountKey), 'testing');
}

it('invalidates the previous unconsumed link when a new one is requested', function (): void {
    Notification::fake();
    $user = eligibleOwner('rotate@salon.co.ke');
    $tokens = app(MagicLinkTokenService::class);

    // First link minted directly.
    $raw1 = issueBoundMagicLink('rotate@salon.co.ke');
    expect(MagicLoginToken::query()->where('email', 'rotate@salon.co.ke')->whereNull('invalidated_at')->count())->toBe(1);

    // Requesting a fresh link supersedes the first. UI-03 made issuance host-bound too, so the
    // request must name the account it is for (the Phase 5 one-argument shape no longer exists).
    app(RequestMagicLink::class)->handle(
        'rotate@salon.co.ke',
        'merchant_administrator',
        accountHostName('merchant_administrator'),
        'testing',
    );

    // The first token can no longer be consumed; exactly one usable link remains.
    expect(consumeOnBoundHost($tokens, $raw1))->toBeNull()
        ->and(MagicLoginToken::query()->where('email', 'rotate@salon.co.ke')->whereNull('invalidated_at')->whereNull('consumed_at')->count())->toBe(1);
});

it('invalidates unconsumed links on logout', function (): void {
    $user = eligibleOwner('logout@salon.co.ke');
    $tokens = app(MagicLinkTokenService::class);
    $raw = issueBoundMagicLink('logout@salon.co.ke');

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/logout')->assertNoContent();

    expect(consumeOnBoundHost($tokens, $raw))->toBeNull();
});

it('invalidates unconsumed links on suspension', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$staffUser, $membership] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);
    $tokens = app(MagicLinkTokenService::class);
    // Bound to the account this user actually holds, so the only thing that can make the consume
    // fail is the suspension under test — not a host mismatch.
    $raw = issueBoundMagicLink($staffUser, 'merchant_front_office');

    app(StaffLifecycleService::class)->suspend($membership);

    expect(consumeOnBoundHost($tokens, $raw, 'merchant_front_office'))->toBeNull();
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
    // The user must exist: UI-03's database CHECK refuses a usable token with no bound user, so
    // `issueBoundMagicLink()` resolves the address to a real record rather than inventing one.
    eligibleOwner('hash@salon.co.ke');
    $raw = issueBoundMagicLink('hash@salon.co.ke');

    $row = MagicLoginToken::query()->where('email', 'hash@salon.co.ke')->firstOrFail();
    expect($row->token_hash)->toBe(hash('sha256', $raw))
        ->and($row->token_hash)->not->toBe($raw)
        ->and($row->getAttributes())->not->toHaveKey('token');
});
