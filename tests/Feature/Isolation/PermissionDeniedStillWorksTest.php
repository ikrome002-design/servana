<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('isolation', 'tenancy', 'permissions');

/*
 | §8.4 row: "Member WITH a role but WITHOUT the required permission → 403
 | permission_denied". Tenant-scope hardening must not swallow a permission denial
 | into a 404: an in-scope resource the actor lacks the capability for is still a
 | clean 403 (not a 404), proving the two layers compose correctly.
 */

it('returns 403 permission_denied (not 404) when an in-scope user lacks the capability', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    // Branch Manager is in-merchant + in-branch scope but lacks branches.create.
    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/branches', ['name' => 'New', 'code' => 'NEW001'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});

it('returns 403 permission_denied for an in-scope branch the user cannot edit-profile', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    // Merchant Admin is in scope for the branch but lacks branch.profile.manage.
    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/branches/{$branch->ulid}", ['name' => 'X'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});
