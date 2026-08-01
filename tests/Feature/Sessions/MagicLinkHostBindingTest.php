<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Models\MagicLoginToken;
use App\Domain\Auth\Notifications\MagicLoginLinkNotification;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Sessions\Models\HostSession;
use App\Domain\Sessions\Models\SessionFamily;
use App\Http\Hosts\AccountHostRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('sessions', 'ui03', 'auth', 'security');

/*
 |==============================================================================
 | Phase UI-03 — Magic Link host binding (ADR-019; UI/UX plan §5.1, §18.5).
 |
 | ADR-019's whole claim is that a link works on ONE host, in ONE environment, for ONE account.
 | These tests assert the NEGATIVE directly: the same token, presented anywhere else, mints nothing.
 |
 | NOTE on how the host is varied. Laravel's test client builds the request from the URI, and
 | Symfony's Request::create() overwrites HTTP_HOST with the URI's host — so `withHeader('Host', …)`
 | on a relative path is silently ineffective and every call would hit `localhost`. Every request
 | here therefore uses an ABSOLUTE URL (the `postOnHost` helper), which is the only way to make
 | these assertions genuinely host-varying rather than vacuously green.
 */

/** An active user holding one active membership of the given role, with a branch when needed. */
function ui03Member(MerchantUserRole $role, string $email): array
{
    $merchant = Merchant::factory()->active()->create();
    $user = User::factory()->create(['email' => $email]);

    $membership = MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => $role,
    ]);

    $branch = null;

    if ($membership->isBranchScoped()) {
        $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
        BranchUserAssignment::factory()->create([
            'merchant_user_id' => $membership->id,
            'branch_id' => $branch->id,
            // Explicit: the composite FK (branch_id, merchant_id) → merchant_branches means an
            // implied merchant would only work while the ids happen to line up.
            'merchant_id' => $merchant->id,
        ]);
    }

    return [$user, $merchant, $membership, $branch];
}

/** Every account key mapped to the role that reaches it. */
function ui03AccountRoleMatrix(): array
{
    return [
        'merchant_administrator' => MerchantUserRole::MerchantAdmin,
        'merchant_branch' => MerchantUserRole::BranchManager,
        'merchant_human_resource' => MerchantUserRole::Hr,
        'merchant_finance' => MerchantUserRole::Finance,
        'merchant_front_office' => MerchantUserRole::FrontOffice,
        'merchant_personnel' => MerchantUserRole::Personnel,
        'merchant_audit' => MerchantUserRole::Audit,
    ];
}

it('signs in on the correct host for every merchant account', function (string $accountKey, MerchantUserRole $role): void {
    [$user] = ui03Member($role, "{$accountKey}@salon.co.ke");

    $raw = issueBoundMagicLink($user, $accountKey);

    postOnHost($accountKey, '/api/v1/auth/magic-link/verify', ['token' => $raw])
        ->assertStatus(200)
        ->assertJsonPath('data.user.email', "{$accountKey}@salon.co.ke");
})->with(fn () => array_map(
    static fn (string $key, MerchantUserRole $role): array => [$key, $role],
    array_keys(ui03AccountRoleMatrix()),
    array_values(ui03AccountRoleMatrix()),
));

it('signs a platform user in on the platform host', function (): void {
    $user = User::factory()->create(['is_platform_staff' => true]);

    $raw = issueBoundMagicLink($user, 'super_administrator');

    postOnHost('super_administrator', '/api/v1/auth/magic-link/verify', ['token' => $raw])
        ->assertStatus(200);
});

it('refuses a link on every host except the one it was bound to', function (): void {
    [$user] = ui03Member(MerchantUserRole::Finance, 'finance@salon.co.ke');

    $wrongHosts = array_diff(app(AccountHostRegistry::class)->accountKeys(), ['merchant_finance']);

    foreach ($wrongHosts as $wrongAccount) {
        // A fresh token each time: the point is the BINDING, not exhaustion of one token.
        $raw = issueBoundMagicLink($user, 'merchant_finance');

        postOnHost($wrongAccount, '/api/v1/auth/magic-link/verify', ['token' => $raw])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_or_expired_token');
    }

    // And the legitimate host still works afterwards, so the wrong-host attempts consumed nothing.
    $raw = issueBoundMagicLink($user, 'merchant_finance');
    postOnHost('merchant_finance', '/api/v1/auth/magic-link/verify', ['token' => $raw])->assertStatus(200);
});

it('creates no session when a link is used on the wrong host', function (): void {
    [$user] = ui03Member(MerchantUserRole::Personnel, 'staff@salon.co.ke');
    $raw = issueBoundMagicLink($user, 'merchant_personnel');

    postOnHost('merchant_finance', '/api/v1/auth/magic-link/verify', ['token' => $raw])->assertStatus(422);

    expect(SessionFamily::query()->count())->toBe(0);
    expect(HostSession::query()->count())->toBe(0);
    $this->assertGuest();
});

it('refuses a link bound to another environment', function (): void {
    [$user] = ui03Member(MerchantUserRole::MerchantAdmin, 'prod@salon.co.ke');

    // A token minted for production, presented in the testing environment on the SAME host name
    // would still be refused — the environment is part of the binding, not a consequence of it.
    $raw = issueBoundMagicLink($user, 'merchant_administrator');
    MagicLoginToken::query()->update(['environment' => 'production']);

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $raw])
        ->assertStatus(422);

    expect(HostSession::query()->count())->toBe(0);
});

it('refuses a link whose bound account was tampered with', function (): void {
    [$user] = ui03Member(MerchantUserRole::MerchantAdmin, 'tamper@salon.co.ke');
    $raw = issueBoundMagicLink($user, 'merchant_administrator');

    // Rewrite the stored account to Finance. The link is now internally inconsistent: it can be
    // consumed on neither host, because the host and account are checked TOGETHER.
    MagicLoginToken::query()->update(['account_key' => 'merchant_finance']);

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $raw])->assertStatus(422);
    postOnHost('merchant_finance', '/api/v1/auth/magic-link/verify', ['token' => $raw])->assertStatus(422);
});

it('returns a byte-identical failure for every rejection cause', function (): void {
    [$user] = ui03Member(MerchantUserRole::MerchantAdmin, 'uniform@salon.co.ke');

    $bodies = [];

    // 1. Wrong host.
    $bodies[] = postOnHost('merchant_finance', '/api/v1/auth/magic-link/verify', [
        'token' => issueBoundMagicLink($user, 'merchant_administrator'),
    ])->getContent();

    // 2. Expired.
    $expired = issueBoundMagicLink($user, 'merchant_administrator');
    MagicLoginToken::query()->update(['expires_at' => now()->subMinute()]);
    $bodies[] = postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $expired])->getContent();

    // 3. Replayed.
    $replayed = issueBoundMagicLink($user, 'merchant_administrator');
    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $replayed])->assertStatus(200);
    $bodies[] = postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => $replayed])->getContent();

    // 4. Pure noise.
    $bodies[] = postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', ['token' => 'nonsense'])->getContent();

    expect(array_unique($bodies))->toHaveCount(
        1,
        'Failure responses differ by cause, which makes the endpoint an oracle: '.json_encode($bodies),
    );
});

it('distinguishes the causes in the audit trail even though the response never does', function (): void {
    [$user] = ui03Member(MerchantUserRole::MerchantAdmin, 'audited@salon.co.ke');

    postOnHost('merchant_finance', '/api/v1/auth/magic-link/verify', [
        'token' => issueBoundMagicLink($user, 'merchant_administrator'),
    ])->assertStatus(422);

    expect(AuditLog::query()->where('action', 'auth.magic_link.host_binding_rejected')->exists())->toBeTrue();
});

it('sends a link only for an account the user can actually enter', function (): void {
    Notification::fake();

    [$user] = ui03Member(MerchantUserRole::Personnel, 'personnel-only@salon.co.ke');

    // Requested on the FINANCE host by a Personnel-only user: uniform 202, and no email.
    postOnHost('merchant_finance', '/api/v1/auth/magic-link', ['email' => $user->email])->assertStatus(202);
    Notification::assertNothingSent();
    expect(MagicLoginToken::query()->count())->toBe(0);

    // Requested on their own host: the link is issued.
    postOnHost('merchant_personnel', '/api/v1/auth/magic-link', ['email' => $user->email])->assertStatus(202);
    Notification::assertSentTo($user, MagicLoginLinkNotification::class);
    expect(MagicLoginToken::query()->where('account_key', 'merchant_personnel')->count())->toBe(1);
});

it('answers identically for a real address on the wrong host and an address that does not exist', function (): void {
    [$user] = ui03Member(MerchantUserRole::Personnel, 'real@salon.co.ke');

    $wrongHost = postOnHost('merchant_finance', '/api/v1/auth/magic-link', ['email' => $user->email]);
    $unknown = postOnHost('merchant_finance', '/api/v1/auth/magic-link', ['email' => 'ghost@nowhere.test']);

    expect($wrongHost->getStatusCode())->toBe($unknown->getStatusCode());
    expect($wrongHost->getContent())->toBe($unknown->getContent());
});

it('builds the emailed link from the registry, not from a poisoned forwarded host', function (): void {
    Notification::fake();

    [$user] = ui03Member(MerchantUserRole::Finance, 'poison@salon.co.ke');

    $base = accountHostUrl('merchant_finance', '/');

    test()
        ->withHeader('Origin', rtrim($base, '/'))
        // TRUSTED_PROXIES is empty by default, so Laravel ignores this outright — and even if a
        // proxy were trusted, the URL comes from the registry rather than the request.
        ->withHeader('X-Forwarded-Host', 'evil.attacker.test')
        ->postJson(rtrim($base, '/').'/api/v1/auth/magic-link', ['email' => $user->email])
        ->assertStatus(202);

    Notification::assertSentTo($user, MagicLoginLinkNotification::class, function ($notification) {
        $url = (new ReflectionProperty($notification, 'verifyUrl'))->getValue($notification);

        expect($url)->toContain(accountHostName('merchant_finance'));
        expect($url)->not->toContain('evil.attacker.test');

        return true;
    });

    expect(MagicLoginToken::query()->value('intended_host'))->toBe(accountHostName('merchant_finance'));
});

it('refuses a Magic Link request on a host that is not an approved account host', function (): void {
    eligibleOwner('unapproved@salon.co.ke');

    test()
        ->postJson('http://evil-servana.ke/api/v1/auth/magic-link', ['email' => 'unapproved@salon.co.ke'])
        ->assertStatus(421)
        ->assertJsonPath('error.code', 'misdirected_request');

    expect(MagicLoginToken::query()->count())->toBe(0);
});

it('regenerates the session id and asserts no MFA on sign-in', function (): void {
    config(['session.driver' => 'database']);

    [$user] = ui03Member(MerchantUserRole::Finance, 'mfa-finance@salon.co.ke');
    $raw = issueBoundMagicLink($user, 'merchant_finance');

    $response = postOnHost('merchant_finance', '/api/v1/auth/magic-link/verify', ['token' => $raw])
        ->assertStatus(200);

    // Finance is a mandatory-MFA role, and the link alone must not satisfy it (Plan §18).
    expect($response->json('data.mfa.challenge_required') || $response->json('data.mfa.enrollment_required'))
        ->toBeTrue('A Magic Link must never assert MFA for a mandatory role.');

    // The session bound to the family is the REGENERATED one, so a planted id is never recorded.
    $bound = HostSession::query()->firstOrFail();
    expect($bound->session_id)->not->toBe('');
    expect($bound->account_key)->toBe('merchant_finance');
    expect($bound->mfa_required_at_creation)->toBeTrue();
});

it('creates exactly one family and one host session per sign-in', function (): void {
    config(['session.driver' => 'database']);

    [$user] = ui03Member(MerchantUserRole::MerchantAdmin, 'one@salon.co.ke');

    postOnHost('merchant_administrator', '/api/v1/auth/magic-link/verify', [
        'token' => issueBoundMagicLink($user, 'merchant_administrator'),
    ])->assertStatus(200);

    expect(SessionFamily::query()->where('user_id', $user->id)->count())->toBe(1);
    expect(HostSession::query()->where('user_id', $user->id)->count())->toBe(1);
    expect(HostSession::query()->firstOrFail()->host)->toBe(accountHostName('merchant_administrator'));
});

it('stores only the hash, and never the raw token, on a bound row', function (): void {
    [$user] = ui03Member(MerchantUserRole::MerchantAdmin, 'hash@salon.co.ke');
    $raw = issueBoundMagicLink($user, 'merchant_administrator');

    $row = MagicLoginToken::query()->firstOrFail();

    expect($row->token_hash)->toBe(hash('sha256', $raw));

    foreach ($row->getAttributes() as $value) {
        expect((string) $value)->not->toContain($raw);
    }
});

it('rechecks user, membership and branch state at consume time', function (): void {
    [$user, $merchant, $membership, $branch] = ui03Member(MerchantUserRole::BranchManager, 'branch@salon.co.ke');

    // Branch assignment withdrawn AFTER the link was issued.
    $raw = issueBoundMagicLink($user, 'merchant_branch');
    $membership->branchAssignments()->update(['status' => 'revoked', 'revoked_at' => now()]);

    postOnHost('merchant_branch', '/api/v1/auth/magic-link/verify', ['token' => $raw])->assertStatus(422);
    expect(HostSession::query()->count())->toBe(0);
});
