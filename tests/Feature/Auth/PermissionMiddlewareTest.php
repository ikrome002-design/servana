<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions');

it('allows a holder of the required permission', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/branches', ['name' => 'Westlands', 'code' => 'WST001'])
        ->assertStatus(201);
});

it('denies a request missing the required permission with permission_denied', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    // Branch Manager lacks branches.create — EnsurePermission → 403.
    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/branches', ['name' => 'New', 'code' => 'NEW001'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});

it('404s a foreign branch before the permission check (no existence leak)', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    $foreignBranch = MerchantBranch::factory()->create([
        'merchant_id' => Merchant::factory()->active()->create()->id,
    ]);

    // Branch scope 404s the foreign branch before EnsurePermission runs.
    $this->actingAs($manager, 'sanctum')
        ->patchJson("/api/v1/branches/{$foreignBranch->ulid}", ['name' => 'X'])
        ->assertStatus(404);
});

it('denies an unscoped branch manager editing a branch they are not assigned to', function (): void {
    [, $merchant] = activeAdmin();
    $assigned = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $other = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$manager] = branchStaff($merchant, $assigned, MerchantUserRole::BranchManager);

    // Same merchant, but outside the manager's branch scope → 403 no_branch_scope
    // (EnsureBranchScope runs before EnsurePermission).
    $this->actingAs($manager, 'sanctum')
        ->patchJson("/api/v1/branches/{$other->ulid}", ['name' => 'X'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'no_branch_scope');
});
