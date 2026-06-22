<?php

declare(strict_types=1);

use App\Domain\Auth\Services\AccessRevocationService;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('auth', 'security');

/*
 | Central credential revocation (Plan §79 R6, REM-SESS-001). Real Postgres
 | `sessions` rows (one per device) and `personal_access_tokens` rows are removed
 | by AccessRevocationService; unrelated principals are never touched; every
 | entry point is idempotent.
 */

/** Insert a real database session row for a user, mimicking one signed-in device. */
function seedSession(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'device-'.$id,
        'payload' => 'x',
        'last_activity' => now()->getTimestamp(),
    ]);
}

it('revokes every database session across multiple devices for a user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    seedSession($user, 'phone');
    seedSession($user, 'laptop');
    seedSession($user, 'tablet');
    seedSession($other, 'unrelated');

    $summary = app(AccessRevocationService::class)->revokeForUser($user);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($summary->sessionsRevoked)->toBe(3)
        // An unrelated active user keeps their session.
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1);
});

it('revokes Sanctum personal-access tokens where present', function (): void {
    $user = User::factory()->create();

    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id,
        'name' => 'device',
        'token' => hash('sha256', 'tok-'.$user->id),
        'abilities' => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $summary = app(AccessRevocationService::class)->revokeForUser($user);

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0)
        ->and($summary->tokensRevoked)->toBe(1);
});

it('is idempotent — a second revocation revokes nothing and never restores access', function (): void {
    $user = User::factory()->create();
    seedSession($user, 'phone');

    $service = app(AccessRevocationService::class);

    $first = $service->revokeForUser($user);
    $second = $service->revokeForUser($user);

    expect($first->sessionsRevoked)->toBe(1)
        ->and($second->sessionsRevoked)->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('revokes only the affected member when revoking a membership', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$userA, $membershipA] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);
    [$userB] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    seedSession($userA, 'a-device');
    seedSession($userB, 'b-device');

    app(AccessRevocationService::class)->revokeForMembership($membershipA);

    expect(DB::table('sessions')->where('user_id', $userA->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $userB->id)->count())->toBe(1);
});

it('revokes every member of a merchant when revoking the merchant', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$userA] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);
    [$userB] = branchStaff($merchant, $branch, MerchantUserRole::Finance);
    $outsider = User::factory()->create();

    seedSession($userA, 'a');
    seedSession($userB, 'b');
    seedSession($outsider, 'out');

    $summary = app(AccessRevocationService::class)->revokeForMerchant($merchant);

    expect(DB::table('sessions')->where('user_id', $userA->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $userB->id)->count())->toBe(0)
        ->and($summary->usersAffected)->toBeGreaterThanOrEqual(2)
        // A user in no relationship to the merchant is unaffected.
        ->and(DB::table('sessions')->where('user_id', $outsider->id)->count())->toBe(1);
});
