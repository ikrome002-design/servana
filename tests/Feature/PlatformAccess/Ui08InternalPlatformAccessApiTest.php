<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\PlatformAccess\Enums\PlatformAccessStatus;
use App\Domain\PlatformAccess\Models\PlatformAccessInvitation;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Domain\Sessions\Models\SessionFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class)->group('platform-access', 'ui08', 'ui08-internal-access');

// The permission catalogue is a SEEDED authority, not a migration artifact, so these cases seed it:
// a deny override references a real `permissions` row, and the scope-guard trigger reads its
// category. Asserting against an empty catalogue would prove nothing.
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 | COR-UI08-001 §11 — the internal platform access API (navigation map §5.4.19).
 |
 | Roster and lifecycle, with the two authorized permission keys and nothing else. Reads need
 | `platform.internal_access.view`; every mutation needs `.manage` plus MFA and a fresh
 | `platform_access_administration` step-up.
 */

function ui08ApiAdmin(): User
{
    $user = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($user);
    PlatformAccessMembership::factory()->create(['user_id' => $user->id]);

    return $user;
}

/** @return list<string> */
function ui08AccessReadRoutes(): array
{
    return [
        '/api/v1/platform/internal-access/users',
        '/api/v1/platform/internal-access/invitations',
    ];
}

// --- Permission authority -----------------------------------------------------------------------

it('adds exactly the two authorized internal-access keys, granted to super_admin only', function (): void {
    $matrix = Yaml::parseFile(base_path('docs/auth/permission-matrix.yaml'));

    expect($matrix['keys'])->toHaveCount(169);

    foreach (['platform.internal_access.view', 'platform.internal_access.manage'] as $key) {
        expect($matrix['keys'])->toHaveKey($key);
        expect($matrix['keys'][$key]['default_roles'])->toBe(['super_admin']);
        expect($matrix['keys'][$key]['scope'])->toBe('platform');
        expect($matrix['keys'][$key]['mfa_required'])->toBeTrue();
    }

    // The registry, the database projection and the frontend contract all agree — proven by the
    // dedicated permission suite; here we pin that no THIRD internal-access key crept in.
    $internal = array_filter(
        array_keys($matrix['keys']),
        static fn (string $key): bool => str_contains($key, 'internal_access'),
    );
    expect($internal)->toHaveCount(2);
});

it('grants neither internal-access key to any merchant-side role', function (): void {
    $matrix = Yaml::parseFile(base_path('docs/auth/permission-matrix.yaml'));

    foreach (['platform.internal_access.view', 'platform.internal_access.manage'] as $key) {
        foreach ($matrix['keys'][$key]['default_roles'] as $role) {
            expect($role)->toBe('super_admin', $key.' must never reach a merchant-side role');
        }
    }
});

// --- Authorization boundaries -------------------------------------------------------------------

it('lets a super administrator read the roster and invitations', function (): void {
    PlatformAccessMembership::factory()->count(2)->create();

    foreach (ui08AccessReadRoutes() as $route) {
        test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiAdmin(), 'sanctum')
            ->getJson($route)
            ->assertOk();
    }
});

it('denies a merchant user every internal-access route', function (): void {
    $scn = invoiceScenario();

    foreach (ui08AccessReadRoutes() as $route) {
        test()->statefulMfa(now()->getTimestamp())->actingAs($scn['actor'], 'sanctum')
            ->getJson($route)
            ->assertForbidden();
    }

    $target = PlatformAccessMembership::factory()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs($scn['actor'], 'sanctum')
        ->postJson("/api/v1/platform/internal-access/users/{$target->ulid}/suspend", ['reason' => 'A merchant user must never reach this.'],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertForbidden();

    expect($target->refresh()->status)->toBe(PlatformAccessStatus::Active);
});

it('denies an unauthenticated caller', function (): void {
    test()->getJson('/api/v1/platform/internal-access/users')->assertUnauthorized();
});

it('refuses every mutation when the step-up is stale but still allows the read', function (): void {
    $admin = ui08ApiAdmin();
    $target = PlatformAccessMembership::factory()->create();
    $stale = now()->subHours(2)->getTimestamp();

    test()->statefulMfa($stale)->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/platform/internal-access/users')
        ->assertOk();

    foreach (['suspend', 'deactivate'] as $action) {
        test()->statefulMfa($stale)->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/internal-access/users/{$target->ulid}/{$action}", ['reason' => 'Stale step-up attempt.'],
                ['Idempotency-Key' => 'idem-'.Str::random(24)])
            ->assertForbidden();
    }

    expect($target->refresh()->status)->toBe(PlatformAccessStatus::Active);
});

it('does not enumerate an unknown membership identifier', function (): void {
    test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/internal-access/users/'.Str::ulid())
        ->assertNotFound();
});

// --- Lifecycle ------------------------------------------------------------------------------------

it('suspends, reactivates and deactivates, emitting the matching audit event each time', function (): void {
    $admin = ui08ApiAdmin();
    $target = PlatformAccessMembership::factory()->create();

    foreach ([
        ['suspend', 'suspended', 'platform.internal_access.suspended'],
        ['reactivate', 'active', 'platform.internal_access.reactivated'],
        ['deactivate', 'deactivated', 'platform.internal_access.deactivated'],
    ] as [$action, $status, $event]) {
        test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform/internal-access/users/{$target->ulid}/{$action}", ['reason' => 'Lifecycle case: '.$action.'.'],
                ['Idempotency-Key' => 'idem-'.Str::random(24)])
            ->assertOk()
            ->assertJsonPath('data.status', $status);

        expect(AuditLog::query()->where('action', $event)->exists())->toBeTrue($event.' was not recorded');
    }
});

it('requires a reason on every lifecycle mutation', function (): void {
    $admin = ui08ApiAdmin();
    $target = PlatformAccessMembership::factory()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/internal-access/users/{$target->ulid}/suspend", [],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    expect($target->refresh()->status)->toBe(PlatformAccessStatus::Active);
});

it('revokes session families with a truthful reason and exposes no token material', function (): void {
    $admin = ui08ApiAdmin();
    $target = PlatformAccessMembership::factory()->create();

    SessionFamily::factory()->count(2)->create(['user_id' => $target->user_id]);

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/internal-access/users/{$target->ulid}/sessions/revoke", ['reason' => 'Suspected credential compromise.'],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertOk();

    // The service counts HOST SESSIONS, not families; these families carry none, so the count is 0
    // and the response says `sessions_revoked` rather than a misleading family count.
    expect($response->json('data.sessions_revoked'))->toBe(0);

    // What matters is that BOTH families are now revoked, with the reason added for exactly this
    // case — never a false "the owner revoked their own session".
    expect(SessionFamily::query()->where('user_id', $target->user_id)->whereNotNull('revoked_at')->count())->toBe(2)
        ->and(SessionFamily::query()->where('revoked_reason', 'platform_access_sessions_revoked')->count())->toBe(2);

    $body = (string) $response->getContent();
    foreach (['token', 'session_id', 'cookie', 'secret'] as $forbidden) {
        expect(str_contains(strtolower($body), $forbidden))->toBeFalse('leaked '.$forbidden);
    }
});

it('replaces the deny-override set and refuses a non-platform permission', function (): void {
    $admin = ui08ApiAdmin();
    $target = PlatformAccessMembership::factory()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/platform/internal-access/users/{$target->ulid}/permissions", [
            'denied_permissions' => ['platform.merchant.view'],
            'reason' => 'Scoping this administrator down.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertOk()
        ->assertJsonPath('data.denied_permissions.0', 'platform.merchant.view');

    // An empty array is meaningful: it clears every override.
    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/platform/internal-access/users/{$target->ulid}/permissions", [
            'denied_permissions' => [],
            'reason' => 'Restoring the role defaults.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertOk();

    expect($target->permissionOverrides()->count())->toBe(0);

    // A merchant-scope key is refused before it can reach the trigger.
    $merchantKey = (string) Permission::query()->where('category', '!=', 'platform')->value('key');

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/platform/internal-access/users/{$target->ulid}/permissions", [
            'denied_permissions' => [$merchantKey],
            'reason' => 'Attempting to reference a merchant key.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'platform_access.non_platform_permission');
});

it('exposes no role field, so no merchant role can be assigned through this surface', function (): void {
    $admin = ui08ApiAdmin();
    $target = PlatformAccessMembership::factory()->create();

    $response = test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/platform/internal-access/users/{$target->ulid}")
        ->assertOk();

    expect($response->json('data.role_key'))->toBe('super_admin');

    // A role supplied by the client is simply not part of any request contract here.
    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/platform/internal-access/invitations', [
            'email' => 'someone@example.com',
            'reason' => 'A supplied role must have no effect.',
            'role_key' => 'merchant_admin',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(202);

    expect(
        PlatformAccessInvitation::query()
            ->where('email', 'someone@example.com')
            ->value('role_key'),
    )->toBe('super_admin');
});

it('never exposes a token hash on the invitation surface', function (): void {
    PlatformAccessInvitation::factory()->create();

    $body = (string) test()->statefulMfa(now()->getTimestamp())->actingAs(ui08ApiAdmin(), 'sanctum')
        ->getJson('/api/v1/platform/internal-access/invitations')
        ->assertOk()
        ->getContent();

    expect(str_contains($body, 'token_hash'))->toBeFalse()
        ->and(str_contains($body, 'token'))->toBeFalse();
});
