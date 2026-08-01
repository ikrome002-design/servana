<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Mfa\MfaRequirementResolver;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Sessions\Enums\HandoffRejectionReason;
use App\Domain\Sessions\Enums\SessionRevocationReason;
use App\Domain\Sessions\Models\AccountContextHandoff;
use App\Domain\Sessions\Models\HostSession;
use App\Domain\Sessions\Services\AccountContextResolver;
use App\Domain\Sessions\Services\ContextHandoffService;
use App\Domain\Sessions\Services\SessionFamilyService;
use App\Http\Hosts\AccountHostUrlGenerator;
use App\Http\Requests\Auth\SwitchAccountContextRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('sessions', 'ui03', 'security', 'authorization');

/*
 |==============================================================================
 | Phase UI-03 — account-context discovery, handoff issuance and target consumption
 | (ADR-018 steps 1–10; UI/UX plan §5.3, §5.4).
 |
 | The property under test is ADR-018 step 7: the target session is built from CURRENT database
 | state, never from what the source session believed. Every "stale authority" case below exists
 | because each is a way that belief could have gone out of date between mint and consume.
 */

beforeEach(function (): void {
    // A real database-backed session, so the source host session is a genuine one.
    config(['session.driver' => 'database']);
});

/**
 * A user who legitimately holds a Personnel context in one merchant and a Finance context in
 * ANOTHER — the real multi-context human ADR-018 exists for.
 *
 * WHY TWO MERCHANTS. `merchant_users` carries `UNIQUE (merchant_id, user_id)`, so one human holds
 * at most one membership per merchant: two roles inside one salon is not a state the schema
 * permits, and a fixture that faked it would be testing something that cannot happen. The genuine
 * multi-context cases are (a) memberships in different merchants, as here, and (b) platform staff
 * who also hold a merchant membership. Both are covered.
 *
 * This also makes the tenant-isolation assertions meaningful: the target context must name the
 * RIGHT merchant, and the source context's merchant must not leak into it.
 *
 * @return array{0: User, 1: Merchant, 2: MerchantUser, 3: MerchantUser, 4: MerchantBranch}
 */
function ui03MultiContextUser(): array
{
    $user = User::factory()->create();

    $personnelMerchant = Merchant::factory()->active()->create();
    $personnelBranch = MerchantBranch::factory()->create(['merchant_id' => $personnelMerchant->id]);
    $personnel = MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $personnelMerchant->id,
        'role' => MerchantUserRole::Personnel,
    ]);
    BranchUserAssignment::factory()->create([
        'merchant_user_id' => $personnel->id,
        'branch_id' => $personnelBranch->id,
        'merchant_id' => $personnelMerchant->id,
    ]);

    $financeMerchant = Merchant::factory()->active()->create();
    $financeBranch = MerchantBranch::factory()->create(['merchant_id' => $financeMerchant->id]);
    $finance = MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $financeMerchant->id,
        'role' => MerchantUserRole::Finance,
    ]);
    BranchUserAssignment::factory()->create([
        'merchant_user_id' => $finance->id,
        'branch_id' => $financeBranch->id,
        'merchant_id' => $financeMerchant->id,
    ]);

    return [$user, $financeMerchant, $personnel, $finance, $financeBranch];
}

/**
 * Establish a signed-in SOURCE host session with a known session id.
 *
 * The real Magic Link flow — including the family and host-session binding it creates — is
 * exercised end to end in `MagicLinkHostBindingTest`. Here the binding is written directly so a
 * test can control the id, which is what lets the handoff assertions below be about the HANDOFF
 * rather than about session plumbing. See the harness note further down for why the switch
 * endpoint itself is driven through its service.
 */
function ui03SignInOn(User $user, string $accountKey): string
{
    // A concrete, known session id. `withSession()` cannot be used: `StartSession` calls
    // `setId($request->cookies->get(...))` on every request, so with no cookie present it mints a
    // fresh id each time and the binding written here would never be the one the request reads.
    // Sending the id as a cookie (see ui03SwitchRequest) is what makes the two agree.
    $sessionId = Str::random(40);

    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $user->id,
        'ip_address' => null,
        'user_agent' => null,
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $context = app(AccountContextResolver::class)
        ->findForUser($user, $accountKey, 'testing');

    expect($context)->not->toBeNull("The user does not hold a {$accountKey} context.");

    $families = app(SessionFamilyService::class);
    $family = $families->startFamily($user);

    $families->bindHostSession(
        family: $family,
        user: $user,
        sessionId: $sessionId,
        context: $context,
        host: accountHostName($accountKey),
        mfaRequired: app(MfaRequirementResolver::class)->isRequired($user),
    );

    return $sessionId;
}

/**
 * Sign in on the source host, mint a handoff for the target, and return the RAW token.
 *
 * Goes through the real service rather than the HTTP switch endpoint so a test can then mutate
 * state between mint and consume — which is precisely the window every stale-authority case here
 * is about.
 */
function ui03IssueRawHandoff(User $user, string $sourceAccountKey, string $targetAccountKey, ?string $redirectPath = null): string
{
    ui03SignInOn($user, $sourceAccountKey);

    $sourceSession = HostSession::query()->where('account_key', $sourceAccountKey)->firstOrFail();
    $target = app(AccountContextResolver::class)
        ->findForUser($user, $targetAccountKey, 'testing');

    expect($target)->not->toBeNull();

    $url = app(ContextHandoffService::class)->issue(
        user: $user,
        sourceHostSession: $sourceSession,
        target: $target,
        // Validated at the boundary exactly as the controller does, so an unsafe value is dropped
        // here too rather than reaching the row.
        redirectPath: app(AccountHostUrlGenerator::class)->safeRelativePath($redirectPath),
        ipAddress: null,
        userAgent: null,
    );

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) $query['token'];
}

/*
 |------------------------------------------------------------------------------
 | HARNESS NOTE — why the switch ENDPOINT is exercised through its service here.
 |
 | Minting a handoff requires the request to arrive on the SOURCE session the host binding was
 | written for. Laravel's test client cannot reproduce that: `StartSession` calls
 | `setId($request->cookies->get(...))` on every request, and neither `withSession()`,
 | `withCookie()` nor replaying the real `Set-Cookie` from the sign-in response restores that id in
 | this suite — every request gets a fresh session, so `findBySessionId()` legitimately finds
 | nothing and the endpoint correctly refuses. Confirmed with a throwaway probe route: the session
 | id seen inside the request never matched the id the sign-in had bound.
 |
 | That is a HARNESS limitation, not a product defect, and it is classified as one in
 | docs/proof/ui-03.md. The security properties are therefore proven where they live —
 | ContextHandoffService and AccountContextResolver — while the CONSUME half is still exercised
 | over real HTTP on the real target host (that half needs no pre-existing session, which is the
 | entire point of a handoff). The browser proof covers the source half against a real browser,
 | which does keep its cookies.
 */

/** The opaque context id for one of a user's contexts. */
function ui03ContextId(User $user, string $accountKey): string
{
    $context = app(AccountContextResolver::class)
        ->findForUser($user, $accountKey, 'testing');

    expect($context)->not->toBeNull("The user does not hold a {$accountKey} context.");

    return $context->contextId;
}

it('lists only the contexts a user may currently enter', function (): void {
    [$user, , , $finance] = ui03MultiContextUser();

    $response = $this->actingAs($user, 'sanctum')
        ->getJson(accountHostUrl('merchant_personnel', '/api/v1/auth/account-contexts'))
        ->assertStatus(200);

    $keys = collect($response->json('data'))->pluck('account_key')->sort()->values()->all();
    expect($keys)->toBe(['merchant_finance', 'merchant_personnel']);

    // Suspending the Finance membership removes the context entirely — not "listed but blocked".
    $finance->update(['status' => MerchantUserStatus::Suspended]);

    $after = $this->actingAs($user->fresh(), 'sanctum')
        ->getJson(accountHostUrl('merchant_personnel', '/api/v1/auth/account-contexts'))
        ->assertStatus(200);

    expect(collect($after->json('data'))->pluck('account_key')->all())->toBe(['merchant_personnel']);
});

it('never lists another tenant’s context or a permission array', function (): void {
    [$user] = ui03MultiContextUser();

    // A completely unrelated merchant with its own admin.
    $stranger = Merchant::factory()->active()->create(['name' => 'Someone Else Ltd']);
    MerchantUser::factory()->create([
        'user_id' => User::factory()->create()->id,
        'merchant_id' => $stranger->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson(accountHostUrl('merchant_personnel', '/api/v1/auth/account-contexts'))
        ->assertStatus(200);

    expect($response->getContent())->not->toContain('Someone Else Ltd');

    foreach ($response->json('data') as $context) {
        expect($context)->not->toHaveKey('permissions');
        // Internal ids never leave the server; the merchant is named by its ULID.
        expect($context['merchant_id'])->not->toBeNumeric();
    }
});

it('mints a target URL from the registry and hashes the token at rest', function (): void {
    [$user] = ui03MultiContextUser();
    ui03SignInOn($user, 'merchant_personnel');

    $sourceSession = HostSession::query()->where('account_key', 'merchant_personnel')->firstOrFail();
    $target = app(AccountContextResolver::class)
        ->findForUser($user, 'merchant_finance', 'testing');

    $targetUrl = app(ContextHandoffService::class)->issue(
        user: $user,
        sourceHostSession: $sourceSession,
        target: $target,
        redirectPath: null,
        ipAddress: '203.0.113.9',
        userAgent: 'probe/1.0',
    );

    expect($targetUrl)->toStartWith('http://'.accountHostName('merchant_finance'));
    expect($targetUrl)->toContain('/auth/switch?token=');

    parse_str((string) parse_url($targetUrl, PHP_URL_QUERY), $query);

    $handoff = AccountContextHandoff::query()->firstOrFail();
    expect($handoff->token_hash)->toBe(hash('sha256', $query['token']));

    // The raw token appears in NO stored attribute — and neither does the IP or the user agent,
    // because both are hashed. A leaked row cannot re-identify the requester.
    foreach ($handoff->getAttributes() as $stored) {
        expect((string) $stored)->not->toContain($query['token']);
        expect((string) $stored)->not->toContain('203.0.113.9');
        expect((string) $stored)->not->toContain('probe/1.0');
    }
});

it('refuses a context id that is not one of this user’s', function (): void {
    [$user] = ui03MultiContextUser();

    $other = User::factory()->create(['is_platform_staff' => true]);
    $resolver = app(AccountContextResolver::class);

    // A perfectly well-formed id — for SOMEONE ELSE'S context. It resolves to nothing, because
    // resolution is membership of the REQUESTING user's own freshly derived list, not a lookup.
    expect($resolver->findByContextId($user, ui03ContextId($other, 'super_administrator'), 'testing'))->toBeNull();
    expect($resolver->findByContextId($user, str_repeat('a', 32), 'testing'))->toBeNull();

    // And the endpoint refuses a malformed id before any of that (validation runs first).
    $this->actingAs($user, 'sanctum')
        ->postJson(accountHostUrl('merchant_personnel', '/api/v1/auth/account-contexts/switch'), [
            'context_id' => 'not-a-context-id',
        ])
        ->assertStatus(422);

    expect(AccountContextHandoff::query()->count())->toBe(0);
});

it('accepts nothing from the browser but an opaque context id', function (): void {
    // Structural, not behavioural. The request object has no rule — and therefore no reader — for
    // any authority-bearing field, so honouring one would require adding it here deliberately.
    $rules = (new SwitchAccountContextRequest)->rules();

    expect(array_keys($rules))->toBe(['context_id', 'redirect']);

    foreach (['account_key', 'target_host', 'role', 'permissions', 'merchant_id', 'branch_id', 'mfa'] as $forbidden) {
        expect($rules)->not->toHaveKey($forbidden);
    }

    // And the target the service records comes from the RESOLVED context, never from input.
    [$user] = ui03MultiContextUser();
    ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance');

    $handoff = AccountContextHandoff::query()->firstOrFail();
    expect($handoff->target_account_key)->toBe('merchant_finance');
    expect($handoff->target_host)->toBe(accountHostName('merchant_finance'));
});

it('creates a target session carrying only the target context', function (): void {
    [$user, , $personnel, $finance] = ui03MultiContextUser();
    $raw = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance');

    $consume = test()->get(accountHostUrl('merchant_finance', '/auth/switch?token='.$raw));
    $consume->assertRedirect();
    $consume->assertHeader('Referrer-Policy', 'no-referrer');
    expect($consume->headers->get('Location'))->not->toContain('token=');

    $target = HostSession::query()->where('account_key', 'merchant_finance')->firstOrFail();
    $source = HostSession::query()->where('account_key', 'merchant_personnel')->firstOrFail();

    // Same family — that is what makes global revocation reach both.
    expect($target->session_family_id)->toBe($source->session_family_id);
    // …but a DIFFERENT membership: the target carries only its own context.
    expect($target->merchant_user_id)->toBe($finance->id);
    expect($source->merchant_user_id)->toBe($personnel->id);
    expect($target->session_id)->not->toBe($source->session_id);
    // Finance is mandatory-MFA; the requirement was re-resolved for the new session.
    expect($target->mfa_required_at_creation)->toBeTrue();
});

it('rejects a replayed handoff and records it as a replay', function (): void {
    [$user] = ui03MultiContextUser();
    $raw = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance');

    $url = accountHostUrl('merchant_finance', '/auth/switch?token='.$raw);

    test()->get($url)->assertRedirect();
    $replay = test()->get($url);

    // Uniform failure: the target account's own sign-in page, with no detail at all.
    $replay->assertRedirect();
    expect($replay->headers->get('Location'))->toContain('/auth/login?switch=failed');

    expect(HostSession::query()->where('account_key', 'merchant_finance')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'auth.context_handoff.replay_rejected')->exists())->toBeTrue();
});

it('rejects a handoff presented on the wrong target host', function (): void {
    [$user] = ui03MultiContextUser();
    $raw = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance');

    // Same token, wrong host. `/auth/switch` exists on every account host, so this is a real
    // substitution attempt rather than a 404.
    test()->get(accountHostUrl('merchant_personnel', '/auth/switch?token='.$raw))
        ->assertRedirect();

    expect(HostSession::query()->where('account_key', 'merchant_finance')->count())->toBe(0);
    expect(AccountContextHandoff::query()->firstOrFail()->invalidated_reason)
        ->toBe(HandoffRejectionReason::WrongHost);
});

it('rejects an expired handoff', function (): void {
    [$user] = ui03MultiContextUser();
    $raw = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance');

    // `created_at` has to move too: `expires_at > created_at` is a database CHECK, not a convention.
    AccountContextHandoff::query()->update([
        'created_at' => now()->subMinutes(10),
        'expires_at' => now()->subMinute(),
    ]);

    test()->get(accountHostUrl('merchant_finance', '/auth/switch?token='.$raw))->assertRedirect();

    expect(HostSession::query()->where('account_key', 'merchant_finance')->count())->toBe(0);
    expect(AccountContextHandoff::query()->firstOrFail()->invalidated_reason)
        ->toBe(HandoffRejectionReason::Expired);
});

it('rejects a handoff whose target membership was removed after issuance', function (): void {
    [$user, , , $finance] = ui03MultiContextUser();
    $raw = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance');

    $finance->update(['status' => MerchantUserStatus::Deactivated]);

    test()->get(accountHostUrl('merchant_finance', '/auth/switch?token='.$raw))->assertRedirect();

    expect(HostSession::query()->where('account_key', 'merchant_finance')->count())->toBe(0);
    expect(AccountContextHandoff::query()->firstOrFail()->invalidated_reason)
        ->toBe(HandoffRejectionReason::TargetUnavailable);
});

it('rejects a handoff whose target role changed after issuance', function (): void {
    [$user, , , $finance] = ui03MultiContextUser();
    $raw = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance');

    // The membership survives, but it is no longer Finance — so the Finance context it fed is gone.
    $finance->update(['role' => MerchantUserRole::Audit]);

    test()->get(accountHostUrl('merchant_finance', '/auth/switch?token='.$raw))->assertRedirect();

    expect(HostSession::query()->where('account_key', 'merchant_finance')->count())->toBe(0);
});

it('rejects a handoff whose target branch assignment was withdrawn after issuance', function (): void {
    [$user, , , $finance] = ui03MultiContextUser();
    $raw = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance');

    $finance->branchAssignments()->update(['status' => 'revoked', 'revoked_at' => now()]);

    test()->get(accountHostUrl('merchant_finance', '/auth/switch?token='.$raw))->assertRedirect();

    expect(HostSession::query()->where('account_key', 'merchant_finance')->count())->toBe(0);
});

it('rejects a handoff after the user is suspended', function (): void {
    [$user] = ui03MultiContextUser();
    $raw = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance');

    DB::table('users')->where('id', $user->id)->update(['status' => User::STATUS_SUSPENDED]);

    test()->get(accountHostUrl('merchant_finance', '/auth/switch?token='.$raw))->assertRedirect();

    expect(HostSession::query()->where('account_key', 'merchant_finance')->count())->toBe(0);
    expect(AccountContextHandoff::query()->firstOrFail()->invalidated_reason)
        ->toBe(HandoffRejectionReason::UserIneligible);
});

it('rejects a handoff after the source family is revoked', function (): void {
    [$user] = ui03MultiContextUser();
    $raw = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance');

    $family = HostSession::query()->where('account_key', 'merchant_personnel')->firstOrFail()->family;
    app(SessionFamilyService::class)->revokeFamily($family, SessionRevocationReason::GlobalLogout);

    test()->get(accountHostUrl('merchant_finance', '/auth/switch?token='.$raw))->assertRedirect();

    expect(HostSession::query()->where('account_key', 'merchant_finance')->active()->count())->toBe(0);
});

it('rejects an unsafe deep link rather than following it', function (): void {
    [$user] = ui03MultiContextUser();

    // Every shape that has been used to smuggle an absolute destination past a
    // "does it start with a slash" check. An unsafe value is DROPPED, never cleaned and carried.
    foreach ([
        'https://evil.test/steal',
        '//evil.test',
        '/\\evil.test',
        '/dash\\board',
        "/dash\nboard",
        'javascript:alert(1)',
        '/x://evil.test',
        'dashboard',
    ] as $unsafe) {
        expect(app(AccountHostUrlGenerator::class)->safeRelativePath($unsafe))
            ->toBeNull("Unsafe redirect accepted: {$unsafe}");
    }

    $raw = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance', 'https://evil.test/steal');

    expect(AccountContextHandoff::query()->firstOrFail()->redirect_path)->toBeNull();

    $consume = test()->get(accountHostUrl('merchant_finance', '/auth/switch?token='.$raw));
    expect($consume->headers->get('Location'))->not->toContain('evil.test');
});

it('preserves a SAFE deep link through the switch', function (): void {
    [$user] = ui03MultiContextUser();
    $raw = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance', '/finance/invoices');

    $consume = test()->get(accountHostUrl('merchant_finance', '/auth/switch?token='.$raw));

    expect($consume->headers->get('Location'))->toContain('/finance/invoices');
});

it('lets exactly one of two concurrent consumers win', function (): void {
    [$user] = ui03MultiContextUser();
    $raw = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance');

    $service = app(ContextHandoffService::class);
    $host = accountHostName('merchant_finance');

    // Two consumes of the SAME token. The row lock plus the conditional single-use update mean
    // the second finds `consumed_at` already set.
    $first = $service->consume($raw, 'merchant_finance', $host, 'testing');
    $second = $service->consume($raw, 'merchant_finance', $host, 'testing');

    expect($first)->not->toBeNull();
    expect($second)->toBeNull();
    expect(AccountContextHandoff::query()->whereNotNull('consumed_at')->count())->toBe(1);
});

it('supersedes an earlier unconsumed handoff when a new one is minted', function (): void {
    [$user] = ui03MultiContextUser();

    $firstToken = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance');
    $secondToken = ui03IssueRawHandoff($user, 'merchant_personnel', 'merchant_finance');

    $service = app(ContextHandoffService::class);
    $host = accountHostName('merchant_finance');

    expect($service->consume($firstToken, 'merchant_finance', $host, 'testing'))->toBeNull();
    expect($service->consume($secondToken, 'merchant_finance', $host, 'testing'))->not->toBeNull();
});
