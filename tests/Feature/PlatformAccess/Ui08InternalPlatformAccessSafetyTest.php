<?php

declare(strict_types=1);

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionResolver;
use App\Domain\PlatformAccess\Enums\PlatformAccessStatus;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Domain\PlatformAccess\Models\PlatformAccessPermissionOverride;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('platform-access', 'ui08', 'ui08-internal-access');

// The permission catalogue is a SEEDED authority, not a migration artifact, so these cases seed it:
// a deny override references a real `permissions` row, and the scope-guard trigger reads its
// category. Asserting against an empty catalogue would prove nothing.
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/*
 | COR-UI08-001 §11.5 — the safety rules that must hold when every layer above them is bypassed.
 |
 | A disabled UI button is not enforcement. Each case here drives the real action or writes straight
 | to the database and requires the refusal to come from the server.
 */

function ui08AccessAdmin(): User
{
    $user = User::factory()->create(['is_platform_staff' => true]);
    confirmedTotp($user);
    PlatformAccessMembership::factory()->create(['user_id' => $user->id]);

    return $user;
}

/** Run a statement expected to violate a database guard, inside its own savepoint. */
function ui08AccessGuardViolation(Closure $statement): void
{
    // PostgreSQL aborts the whole transaction on RAISE and RefreshDatabase wraps each test in one,
    // so without a nested transaction (a SAVEPOINT) the first violation poisons every later query.
    expect(static fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

// --- Structural absence -----------------------------------------------------------------------

it('gives the membership table no merchant, branch or staff-profile column, ever', function (): void {
    foreach (['merchant_id', 'branch_id', 'staff_profile_id'] as $column) {
        expect(Schema::hasColumn('platform_access_memberships', $column))
            ->toBeFalse('a platform administrator holds no merchant structure: '.$column.' must not exist');
    }
});

it('permits only the super_admin role key at the database', function (): void {
    ui08AccessGuardViolation(fn () => DB::table('platform_access_memberships')->insert([
        'ulid' => (string) Str::ulid(),
        'user_id' => User::factory()->create()->id,
        'role_key' => 'merchant_admin',
        'status' => 'active',
        'activated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]));
});

it('permits only one platform membership per user', function (): void {
    $membership = PlatformAccessMembership::factory()->create();

    ui08AccessGuardViolation(fn () => PlatformAccessMembership::factory()->create([
        'user_id' => $membership->user_id,
    ]));
});

it('refuses to claim a state without the evidence that produced it', function (): void {
    // status=active with no activated_at is unrepresentable.
    ui08AccessGuardViolation(fn () => DB::table('platform_access_memberships')->insert([
        'ulid' => (string) Str::ulid(),
        'user_id' => User::factory()->create()->id,
        'role_key' => 'super_admin',
        'status' => 'active',
        'activated_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]));
});

// --- Deny-only overrides ----------------------------------------------------------------------

it('makes a grant override unrepresentable', function (): void {
    $membership = PlatformAccessMembership::factory()->create();
    $permission = Permission::query()->where('category', 'platform')->firstOrFail();

    ui08AccessGuardViolation(fn () => DB::table('platform_access_permission_overrides')->insert([
        'ulid' => (string) Str::ulid(),
        'platform_access_membership_id' => $membership->id,
        'permission_id' => $permission->id,
        'effect' => 'grant',
        'reason' => 'Attempting to grant.',
        'created_by_user_id' => $membership->user_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]));
});

it('refuses a merchant-scope permission in a platform override, at the database', function (): void {
    $membership = PlatformAccessMembership::factory()->create();
    $merchantPermission = Permission::query()->where('category', '!=', 'platform')->firstOrFail();

    // The trigger is the structural half of "no merchant permission may be referenced here".
    ui08AccessGuardViolation(fn () => DB::table('platform_access_permission_overrides')->insert([
        'ulid' => (string) Str::ulid(),
        'platform_access_membership_id' => $membership->id,
        'permission_id' => $merchantPermission->id,
        'effect' => 'deny',
        'reason' => 'Attempting to reference a merchant key.',
        'created_by_user_id' => $membership->user_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]));
});

it('subtracts a deny override from the resolved platform permissions', function (): void {
    $admin = ui08AccessAdmin();
    $membership = PlatformAccessMembership::query()->where('user_id', $admin->id)->firstOrFail();

    $before = app(PermissionResolver::class)->forPlatformStaff($admin->id);
    expect($before)->toContain('platform.merchant.view');

    PlatformAccessPermissionOverride::factory()->create([
        'platform_access_membership_id' => $membership->id,
        'permission_id' => Permission::query()->where('key', 'platform.merchant.view')->value('id'),
    ]);

    $after = app(PermissionResolver::class)->forPlatformStaff($admin->id);

    // Deny-only: the key is gone, and nothing was added.
    expect($after)->not->toContain('platform.merchant.view')
        ->and(count($after))->toBe(count($before) - 1);
});

// --- Self-protection and lockout ----------------------------------------------------------------

it('refuses an administrator acting on their own access', function (): void {
    $admin = ui08AccessAdmin();
    PlatformAccessMembership::factory()->create(); // a second administrator, so quorum is not the cause
    $own = PlatformAccessMembership::query()->where('user_id', $admin->id)->firstOrFail();

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/internal-access/users/{$own->ulid}/suspend", [
            'reason' => 'Attempting to suspend myself.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'platform_access.self_action_forbidden');

    expect($own->refresh()->status)->toBe(PlatformAccessStatus::Active);
});

it('refuses to deactivate the last active administrator', function (): void {
    // Exactly two administrators: the actor, and the only other active one.
    $actor = ui08AccessAdmin();
    $target = PlatformAccessMembership::query()->where('user_id', '!=', $actor->id)->first()
        ?? PlatformAccessMembership::factory()->create();

    // Make the ACTOR inactive so the target is genuinely the last active administrator, while the
    // actor still holds the permission through the request context.
    PlatformAccessMembership::query()
        ->where('user_id', $actor->id)
        ->update(['status' => PlatformAccessStatus::Suspended->value, 'suspended_at' => now()]);

    expect(PlatformAccessMembership::query()->where('status', PlatformAccessStatus::Active->value)->count())->toBe(1);

    test()->statefulMfa(now()->getTimestamp())->actingAs($actor, 'sanctum')
        ->postJson("/api/v1/platform/internal-access/users/{$target->ulid}/deactivate", [
            'reason' => 'Attempting to remove the final active administrator.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'platform_access.last_active_administrator');

    expect($target->refresh()->status)->toBe(PlatformAccessStatus::Active);
});

it('never writes a merchant membership, branch assignment or staff profile', function (): void {
    $admin = ui08AccessAdmin();
    $target = PlatformAccessMembership::factory()->create();

    $before = [
        'merchant_users' => DB::table('merchant_users')->count(),
        'branch_user_assignments' => DB::table('branch_user_assignments')->count(),
        'staff_profiles' => DB::table('staff_profiles')->count(),
    ];

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/internal-access/users/{$target->ulid}/suspend", [
            'reason' => 'A lifecycle change must touch no merchant structure.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertOk();

    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count, $table.' was written by a platform-access action');
    }
});

it('keeps users.is_platform_staff a faithful mirror of the membership status', function (): void {
    $admin = ui08AccessAdmin();
    $target = PlatformAccessMembership::factory()->create();

    expect($target->user->refresh()->is_platform_staff)->toBeTrue();

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/internal-access/users/{$target->ulid}/suspend", ['reason' => 'Mirror check: suspend.'],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertOk();

    expect($target->refresh()->status)->toBe(PlatformAccessStatus::Suspended)
        ->and($target->user->refresh()->is_platform_staff)->toBeFalse();

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/internal-access/users/{$target->ulid}/reactivate", ['reason' => 'Mirror check: reactivate.'],
            ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertOk();

    expect($target->refresh()->status)->toBe(PlatformAccessStatus::Active)
        ->and($target->user->refresh()->is_platform_staff)->toBeTrue();
});

it('treats deactivation as terminal', function (): void {
    $admin = ui08AccessAdmin();
    $target = PlatformAccessMembership::factory()->deactivated()->create();

    test()->statefulMfa(now()->getTimestamp())->actingAs($admin, 'sanctum')
        ->postJson("/api/v1/platform/internal-access/users/{$target->ulid}/reactivate", [
            'reason' => 'Attempting to resurrect a deactivated grant.',
        ], ['Idempotency-Key' => 'idem-'.Str::random(24)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_state_transition');

    expect($target->refresh()->status)->toBe(PlatformAccessStatus::Deactivated);
});
