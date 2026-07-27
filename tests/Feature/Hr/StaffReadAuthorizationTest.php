<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\PermissionOverrideEffect;
use App\Domain\Auth\Models\MerchantUserPermissionOverride;
use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('hr', 'staff', 'security', 'phase23');

/*
 | Phase 23 §14.1 — the HR staff READ authorization boundary (product-owner decision).
 |
 | REGRESSION GUARD. Before Phase 23, `GET /api/v1/staff` carried no EnsurePermission
 | middleware and `StaffController::index()` made no authorize() call, while
 | StaffProfileResource returns an UNMASKED `phone`. Any authenticated merchant member —
 | Front Office, Personnel, and the read-only Audit role included — could enumerate the
 | branch roster with personnel phone numbers (Plan §9.1 personnel-contact extraction;
 | RK-05; Plan §9 rule 17 "no personnel contact-export channel").
 |
 | READ (`staff.view`, HR-only, branch-scoped) is now distinct from MANAGE (`staff.suspend` /
 | `branches.manage_users_lifecycle`), and neither substitutes for the other.
 */

const STAFF_URL = '/api/v1/staff';

/** @return array{merchant: Merchant, branch: MerchantBranch, other: MerchantBranch} */
function staffReadScenario(): array
{
    test()->seed(PermissionSeeder::class);
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $other = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    return compact('merchant', 'branch', 'other');
}

it('requires authentication', function (): void {
    staffReadScenario();

    test()->getJson(STAFF_URL)->assertUnauthorized();
});

it('lets HR read the roster and a staff detail inside its own branch', function (): void {
    $scn = staffReadScenario();
    [$hr] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Hr);
    [, , $colleague] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::FrontOffice);

    test()->actingAs($hr, 'sanctum')->getJson(STAFF_URL)
        ->assertOk()
        ->assertJsonCount(2, 'data');

    test()->actingAs($hr, 'sanctum')->getJson(STAFF_URL.'/'.$colleague->ulid)
        ->assertOk()
        ->assertJsonPath('data.id', $colleague->ulid);
});

it('never lets HR enumerate another merchant staff', function (): void {
    $scn = staffReadScenario();
    [$hr] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Hr);

    $foreignMerchant = Merchant::factory()->active()->create();
    $foreignBranch = MerchantBranch::factory()->create(['merchant_id' => $foreignMerchant->id]);
    [, , $foreignStaff] = branchStaff($foreignMerchant, $foreignBranch, MerchantUserRole::FrontOffice);

    $res = test()->actingAs($hr, 'sanctum')->getJson(STAFF_URL)->assertOk();
    expect(collect($res->json('data'))->pluck('id'))->not->toContain($foreignStaff->ulid);

    // Foreign tenant → 404, never 403: no existence leak (Plan §9 rule 1).
    test()->actingAs($hr, 'sanctum')->getJson(STAFF_URL.'/'.$foreignStaff->ulid)->assertNotFound();
});

it('never lets HR read a staff profile in a branch it is not assigned to', function (): void {
    $scn = staffReadScenario();
    [$hr] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Hr);
    [, , $outOfBranch] = branchStaff($scn['merchant'], $scn['other'], MerchantUserRole::FrontOffice);

    $res = test()->actingAs($hr, 'sanctum')->getJson(STAFF_URL)->assertOk();
    expect(collect($res->json('data'))->pluck('id'))->not->toContain($outOfBranch->ulid);

    // Same-tenant out-of-branch is an AUTHORITY denial, not an existence leak: Plan §9 rule 2
    // documents the 403 posture for same-tenant out-of-branch (contrast the foreign-tenant 404
    // above). This matches the pre-Phase-23 posture of `manage` — the read boundary reuses the
    // established convention rather than inventing a new one.
    test()->actingAs($hr, 'sanctum')->getJson(STAFF_URL.'/'.$outOfBranch->ulid)->assertForbidden();
});

it('denies every merchant role that does not hold staff.view, on BOTH the index and the detail', function (string $role): void {
    $scn = staffReadScenario();
    [$actor] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::from($role));
    [, , $target] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);

    test()->actingAs($actor, 'sanctum')->getJson(STAFF_URL)->assertForbidden();
    test()->actingAs($actor, 'sanctum')->getJson(STAFF_URL.'/'.$target->ulid)->assertForbidden();
})->with([
    // The exact roles that could previously enumerate personnel phone numbers.
    'branch_manager',
    'front_office',
    'personnel',
    'audit',
    'finance',
]);

it('never exposes a personnel phone number to a role without staff.view', function (): void {
    $scn = staffReadScenario();
    [$personnel] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);
    [, , $colleague] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Personnel);

    $res = test()->actingAs($personnel, 'sanctum')->getJson(STAFF_URL)->assertForbidden();

    // The denial body carries no roster and no contact field at all (RK-05).
    expect((string) $res->getContent())
        ->not->toContain($colleague->phone)
        ->and((string) $res->getContent())->not->toContain($colleague->display_name);
});

it('keeps the MANAGE authority unchanged — staff.view alone never suspends, activates or deactivates', function (): void {
    $scn = staffReadScenario();
    [$hr] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Hr);
    [, , $target] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::FrontOffice);

    // HR legitimately holds BOTH staff.view and staff.suspend, so revoke the mutation key only:
    // the read must survive and every lifecycle mutation must fail.
    $hrMembership = $hr->merchantUsers()->firstOrFail();
    foreach (['staff.suspend'] as $key) {
        MerchantUserPermissionOverride::query()->create([
            'merchant_id' => $hrMembership->merchant_id,
            'merchant_user_id' => $hrMembership->id,
            'permission_id' => Permission::query()->where('key', $key)->value('id'),
            'effect' => PermissionOverrideEffect::Deny,
            'granted_by' => User::factory()->create()->id,
            'reason' => 'phase-23 read/mutation separation proof',
        ]);
    }

    test()->actingAs($hr, 'sanctum')->getJson(STAFF_URL)->assertOk();
    test()->actingAs($hr, 'sanctum')->postJson(STAFF_URL.'/'.$target->ulid.'/suspend')->assertForbidden();
    test()->actingAs($hr, 'sanctum')->postJson(STAFF_URL.'/'.$target->ulid.'/activate')->assertForbidden();
    test()->actingAs($hr, 'sanctum')->postJson(STAFF_URL.'/'.$target->ulid.'/deactivate')->assertForbidden();
});

it('honours a revoke override on staff.view (revocable_only) and ignores a grant override', function (): void {
    $scn = staffReadScenario();
    [$hr] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Hr);
    [$frontOffice] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::FrontOffice);

    $permissionId = Permission::query()->where('key', 'staff.view')->value('id');

    // Revoke from HR → the read is gone (revocable_only).
    MerchantUserPermissionOverride::query()->create([
        'merchant_id' => $scn['merchant']->id,
        'merchant_user_id' => $hr->merchantUsers()->firstOrFail()->id,
        'permission_id' => $permissionId,
        'effect' => PermissionOverrideEffect::Deny,
        'granted_by' => User::factory()->create()->id,
        'reason' => 'phase-23 revocable_only proof',
    ]);

    // Grant to Front Office → a NO-OP: staff.view is revocable_only, never grantable (Plan §19.4).
    MerchantUserPermissionOverride::query()->create([
        'merchant_id' => $scn['merchant']->id,
        'merchant_user_id' => $frontOffice->merchantUsers()->firstOrFail()->id,
        'permission_id' => $permissionId,
        'effect' => PermissionOverrideEffect::Grant,
        'granted_by' => User::factory()->create()->id,
        'reason' => 'phase-23 revocable_only proof',
    ]);

    test()->actingAs($hr, 'sanctum')->getJson(STAFF_URL)->assertForbidden();
    test()->actingAs($frontOffice, 'sanctum')->getJson(STAFF_URL)->assertForbidden();
});
