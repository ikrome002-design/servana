<?php

declare(strict_types=1);

use App\Domain\Auth\Services\AccessRevocationService;
use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Services\StaffLifecycleService;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Sessions\Enums\SessionRevocationReason;
use App\Domain\Sessions\Models\HostSession;
use App\Domain\Sessions\Models\SessionFamily;
use App\Domain\Sessions\Services\SessionFamilyService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('sessions', 'ui03', 'security', 'isolation');

/*
 |==============================================================================
 | Phase UI-03 — session family and CROSS-HOST revocation (ADR-018; UI/UX plan §5.2).
 |
 | ADR-018's central claim is that revocation is GLOBAL and provably so. These tests assert it at
 | the level that matters: the underlying row in Laravel's database-backed `sessions` table is gone,
 | not merely that a `revoked_at` stamp was written. A stamp with a live session row behind it would
 | be exactly the kind of "revocation" that looks correct and denies nothing.
 |
 | They also assert the opposite direction, which is easy to get wrong: a NARROW revocation must not
 | over-revoke. Losing one role must not sign a user out of the contexts they still legitimately
 | hold.
 */

/** A user with two active host sessions in one family, on two different account hosts. */
function ui03TwoHostSessions(): array
{
    $merchant = Merchant::factory()->active()->create();
    $user = User::factory()->create();

    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $finance = MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::Finance,
    ]);
    BranchUserAssignment::factory()->create([
        'merchant_user_id' => $finance->id,
        'branch_id' => $branch->id,
        'merchant_id' => $merchant->id,
    ]);

    $family = SessionFamily::factory()->create(['user_id' => $user->id]);

    $sessions = [];

    foreach ([
        ['merchant_finance', 'finance.servana.test', $finance->id, $branch->id],
        ['merchant_personnel', 'staff.servana.test', $finance->id, $branch->id],
    ] as [$accountKey, $host, $membershipId, $branchId]) {
        $sessionId = (string) Str::random(40);

        // A REAL row in the session store, so "revoked" can be proven by its absence.
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);

        $sessions[$accountKey] = HostSession::factory()->create([
            'session_family_id' => $family->id,
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'account_key' => $accountKey,
            'host' => $host,
            'merchant_id' => $merchant->id,
            'merchant_user_id' => $membershipId,
            'branch_id' => $branchId,
        ]);
    }

    return [$user, $merchant, $finance, $branch, $family, $sessions];
}

it('revokes every linked host session and its database session on global logout', function (): void {
    [$user, , , , $family, $sessions] = ui03TwoHostSessions();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(2);

    app(SessionFamilyService::class)->revokeFamily($family, SessionRevocationReason::GlobalLogout);

    // Both bindings marked, and — the part that actually denies access — both session rows gone.
    expect(HostSession::query()->where('user_id', $user->id)->active()->count())->toBe(0);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    expect($family->fresh()->revoked_reason)->toBe(SessionRevocationReason::GlobalLogout);
    expect($family->fresh()->lifecycle_version)->toBe(2);
});

it('is idempotent — a second global revoke changes nothing and restores nothing', function (): void {
    [$user, , , , $family] = ui03TwoHostSessions();

    $service = app(SessionFamilyService::class);
    $service->revokeFamily($family, SessionRevocationReason::GlobalLogout);
    $versionAfterFirst = $family->fresh()->lifecycle_version;

    $second = $service->revokeFamily($family->fresh(), SessionRevocationReason::GlobalLogout);

    expect($second)->toBe(0);
    expect($family->fresh()->lifecycle_version)->toBe($versionAfterFirst);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('revokes every host session when the user is suspended', function (): void {
    [$user] = ui03TwoHostSessions();

    app(AccessRevocationService::class)->revokeForUser($user, SessionRevocationReason::UserSuspended);

    expect(HostSession::query()->where('user_id', $user->id)->active()->count())->toBe(0);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('revokes only the affected merchant contexts when a merchant is suspended', function (): void {
    [$user, $merchant, , , $family] = ui03TwoHostSessions();

    // A second, unrelated context: the same human, a different merchant, still signed in.
    $otherMerchant = Merchant::factory()->active()->create();
    $otherMembership = MerchantUser::factory()->create([
        'user_id' => $user->id,
        'merchant_id' => $otherMerchant->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);
    $survivor = HostSession::factory()->create([
        'session_family_id' => $family->id,
        'user_id' => $user->id,
        'account_key' => 'merchant_administrator',
        'host' => 'servana.test',
        'merchant_id' => $otherMerchant->id,
        'merchant_user_id' => $otherMembership->id,
    ]);

    app(AccessRevocationService::class)->revokeForMerchant($merchant, SessionRevocationReason::MerchantSuspended);

    expect(HostSession::query()->where('merchant_id', $merchant->id)->active()->count())->toBe(0);
    expect($survivor->fresh()->revoked_at)->toBeNull('A context in another merchant must survive.');
});

it('revokes only the branch-bound context when one branch assignment is withdrawn', function (): void {
    [$user, $merchant, $membership, $branch, $family] = ui03TwoHostSessions();

    // A second branch the same membership also covers.
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    BranchUserAssignment::factory()->create([
        'merchant_user_id' => $membership->id,
        'branch_id' => $otherBranch->id,
        'merchant_id' => $merchant->id,
    ]);
    $survivor = HostSession::factory()->create([
        'session_family_id' => $family->id,
        'user_id' => $user->id,
        'account_key' => 'merchant_branch',
        'host' => 'branch.servana.test',
        'merchant_id' => $merchant->id,
        'merchant_user_id' => $membership->id,
        'branch_id' => $otherBranch->id,
    ]);

    $assignment = BranchUserAssignment::query()
        ->where('merchant_user_id', $membership->id)
        ->where('branch_id', $branch->id)
        ->firstOrFail();

    app(StaffLifecycleService::class)->revokeBranchAssignment($assignment);

    expect(HostSession::query()->where('branch_id', $branch->id)->active()->count())->toBe(0);
    expect($survivor->fresh()->revoked_at)->toBeNull('The second branch context must survive.');
});

it('revokes the affected contexts when a membership is suspended', function (): void {
    [$user, , $membership] = ui03TwoHostSessions();

    app(StaffLifecycleService::class)->suspend($membership);

    expect(HostSession::query()->where('merchant_user_id', $membership->id)->active()->count())->toBe(0);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('revokes one session without touching its siblings', function (): void {
    [$user, , , , , $sessions] = ui03TwoHostSessions();

    app(SessionFamilyService::class)->revokeHostSession(
        $sessions['merchant_finance'],
        SessionRevocationReason::SessionRevokedByOwner,
    );

    expect($sessions['merchant_finance']->fresh()->revoked_at)->not->toBeNull();
    expect($sessions['merchant_personnel']->fresh()->revoked_at)->toBeNull();
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
});

it('is idempotent when revoking the same single session twice', function (): void {
    [, , , , , $sessions] = ui03TwoHostSessions();

    $service = app(SessionFamilyService::class);
    $target = $sessions['merchant_finance'];

    $service->revokeHostSession($target, SessionRevocationReason::SessionRevokedByOwner);
    $firstStamp = $target->fresh()->revoked_at;

    $service->revokeHostSession($target->fresh(), SessionRevocationReason::GlobalLogout);

    // The original reason and timestamp stand — a second call must not rewrite history.
    expect($target->fresh()->revoked_at?->toIso8601String())->toBe($firstStamp?->toIso8601String());
    expect($target->fresh()->revoked_reason)->toBe(SessionRevocationReason::SessionRevokedByOwner);
});

it('stores no permission set on any session row', function (): void {
    [, , , , , $sessions] = ui03TwoHostSessions();

    foreach ($sessions as $session) {
        $attributes = $session->fresh()->getAttributes();

        foreach (array_keys($attributes) as $column) {
            expect($column)->not->toMatch('/permission|grant|abilit/i');
        }
    }
});
