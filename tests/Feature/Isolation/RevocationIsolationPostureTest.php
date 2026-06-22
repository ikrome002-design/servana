<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Services\StaffLifecycleService;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('isolation', 'tenancy', 'security');

/*
 | R6 must NOT change the established isolation contract (Plan §8.2/§8.4): a
 | foreign-tenant ULID 404s; a same-tenant unauthorized branch 403s; a suspended
 | merchant 403s `merchant_suspended`. This regression guard pins that posture
 | alongside the new mid-session revocation behaviour.
 */

it('still 404s a foreign-tenant branch ULID (no existence leak)', function (): void {
    [$admin] = activeAdmin();
    $foreign = MerchantBranch::factory()->create([
        'merchant_id' => Merchant::factory()->active()->create()->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/branches/{$foreign->ulid}")
        ->assertStatus(404);
});

it('still 403s a same-tenant branch the staff member is not assigned to', function (): void {
    [, $merchant] = activeAdmin();
    $assigned = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $other = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$staffUser] = branchStaff($merchant, $assigned, MerchantUserRole::BranchManager);

    $this->actingAs($staffUser, 'sanctum')
        ->getJson("/api/v1/branches/{$other->ulid}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'no_branch_scope');
});

it('still 403s a suspended merchant with merchant_suspended', function (): void {
    $merchant = Merchant::factory()->create(['status' => MerchantStatus::Suspended]);
    $user = User::factory()->create();
    MerchantUser::factory()->create([
        'user_id' => $user->id, 'merchant_id' => $merchant->id, 'role' => MerchantUserRole::MerchantAdmin,
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/branches')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'merchant_suspended');
});

it('denies the next branch request after a mid-session branch revoke (posture preserved)', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$staffUser, $membership] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $this->actingAs($staffUser, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}")
        ->assertStatus(200);

    app(StaffLifecycleService::class)->revokeBranchAssignment($membership->branchAssignments()->active()->firstOrFail());

    $this->actingAs($staffUser->fresh(), 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'no_branch_scope');
});
