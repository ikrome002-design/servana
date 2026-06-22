<?php

declare(strict_types=1);

use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Services\StaffLifecycleService;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('security', 'auth', 'isolation');

/*
 | Mid-session revocation against a REAL database-backed session (Plan §79 R6).
 | These tests do NOT use actingAs() for the revocation proof: they sign in via
 | the Magic Link verify flow (creating a real `sessions` row + cookie on
 | Postgres), then prove the NEXT request carrying that cookie is denied once the
 | principal is revoked.
 */

beforeEach(function (): void {
    // Force the database session driver so a genuine session row is persisted
    // and can be revoked (the suite default is the array driver).
    config(['session.driver' => 'database']);
});

/**
 * Sign in via a real Magic Link and return [cookieName, encryptedCookieValue].
 * The value is the encrypted cookie verbatim, replayed with withUnencryptedCookie
 * so EncryptCookies decrypts it exactly as a browser round-trip would.
 *
 * @return array{0: string, 1: string}
 */
function r6RealLogin(string $email): array
{
    $raw = app(MagicLinkTokenService::class)->issue($email);
    $response = postStateful('/api/v1/auth/magic-link/verify', ['token' => $raw]);
    $response->assertStatus(200);

    $name = (string) config('session.cookie');
    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c): bool => $c->getName() === $name);
    expect($cookie)->not->toBeNull();

    return [$name, (string) $cookie->getValue()];
}

function r6Replay(string $name, string $value, string $uri): TestResponse
{
    // Drop the cached guard so the request re-resolves the principal: the
    // SessionGuard re-reads the session's login key and re-loads the User row
    // FRESH from the database — which is what lets EnsureActivePrincipal observe
    // a mid-session status change. (The session itself is the live, real
    // database-backed session established by the Magic Link login.)
    app('auth')->forgetGuards();

    return test()
        ->withHeader('Origin', 'http://localhost')
        ->withUnencryptedCookie($name, $value)
        ->getJson($uri);
}

/** @return array{0: User, 1: MerchantUser} */
function r6FrontOffice(): array
{
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$user, $membership] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    return [$user, $membership];
}

it('authenticates a follow-up request with a real database session', function (): void {
    [$user] = r6FrontOffice();
    [$name, $value] = r6RealLogin($user->email);

    // A genuine session row now exists on Postgres.
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBeGreaterThanOrEqual(1);

    r6Replay($name, $value, '/api/v1/me')
        ->assertStatus(200)
        ->assertJsonPath('data.user.id', $user->ulid);
});

it('deletes the real database session on membership suspension', function (): void {
    [$user, $membership] = r6FrontOffice();
    r6RealLogin($user->email);

    // A real session row backs the signed-in session before revocation.
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBeGreaterThanOrEqual(1);

    app(StaffLifecycleService::class)->suspend($membership);

    // The persistent session is physically gone: a fresh production request
    // would find no session and be unauthenticated. (The membership is also no
    // longer active — see AuthorizationFreshnessTest for the context drop.)
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('denies the next request when the user is suspended even if the session row survives', function (): void {
    // Defence in depth: a session that outlived revocation must still be rejected
    // by the per-request active-principal gate (EnsureActivePrincipal).
    [$user] = r6FrontOffice();
    [$name, $value] = r6RealLogin($user->email);

    // Flip the user to suspended WITHOUT deleting the session row.
    DB::table('users')->where('id', $user->id)->update(['status' => User::STATUS_SUSPENDED]);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBeGreaterThanOrEqual(1);

    r6Replay($name, $value, '/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('denies platform access after a platform user is deactivated mid-session', function (): void {
    $platform = User::factory()->create(['is_platform_staff' => true]);
    [$name, $value] = r6RealLogin($platform->email);

    r6Replay($name, $value, '/api/v1/me')->assertStatus(200);

    DB::table('users')->where('id', $platform->id)->update(['status' => User::STATUS_DEACTIVATED]);

    r6Replay($name, $value, '/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});
