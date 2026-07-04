<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'security');

/*
 | Permission-override freshness (Plan §79 R6). A grant / deny / revoke applied
 | between two requests is reflected on the very next request — there is no
 | persistent authorization cache; TenantContextResolver re-resolves the
 | permission set from the database every request.
 */

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

/** @return list<string> */
function mePermissions(User $user): array
{
    return test()->actingAs($user, 'sanctum')->getJson('/api/v1/me')->json('data.permissions');
}

it('reflects a deny override on the next request and restores it on revoke', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$financeUser, , $financeMembership] = branchStaff($merchant, $branch, MerchantUserRole::Finance);

    // customer_payment.view is a Finance default grant.
    expect(mePermissions($financeUser))->toContain('customer_payment.view');

    // Admin denies it.
    $this->actingAs($admin, 'sanctum')->postJson("/api/v1/staff/{$financeMembership->ulid}/permissions", [
        'permission' => 'customer_payment.view', 'effect' => 'deny',
    ])->assertStatus(200);

    // Next request: gone.
    expect(mePermissions($financeUser))->not->toContain('customer_payment.view');

    // Admin revokes the override.
    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/v1/staff/{$financeMembership->ulid}/permissions/customer_payment.view")
        ->assertStatus(200);

    // Next request: restored.
    expect(mePermissions($financeUser))->toContain('customer_payment.view');
});

it('reflects a grant override on the next request', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$financeUser, , $financeMembership] = branchStaff($merchant, $branch, MerchantUserRole::Finance);

    // refund.approve is grantable (◐) for Finance but not a default.
    expect(mePermissions($financeUser))->not->toContain('refund.approve');

    $this->actingAs($admin, 'sanctum')->postJson("/api/v1/staff/{$financeMembership->ulid}/permissions", [
        'permission' => 'refund.approve', 'effect' => 'grant',
    ])->assertStatus(200);

    expect(mePermissions($financeUser))->toContain('refund.approve');
});

it('enforces a denied permission at the protected route on the next request', function (): void {
    // A Branch Manager holds branch.profile.manage by default and can PATCH its
    // own branch. Deny that permission and prove the mutating route is refused on
    // the very next request (the backend boundary re-reads state, no cache).
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$bmUser, , $bmMembership] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $this->actingAs($bmUser, 'sanctum')
        ->patchJson("/api/v1/branches/{$branch->ulid}", ['name' => 'Renamed'])
        ->assertStatus(200);

    $this->actingAs($admin, 'sanctum')->postJson("/api/v1/staff/{$bmMembership->ulid}/permissions", [
        'permission' => 'branch.profile.manage', 'effect' => 'deny',
    ])->assertStatus(200);

    $this->actingAs($bmUser->fresh(), 'sanctum')
        ->patchJson("/api/v1/branches/{$branch->ulid}", ['name' => 'Again'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});
