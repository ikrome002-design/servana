<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('isolation', 'tenancy');

/*
 | Cross-branch isolation within one merchant (Plan §8.2, §8.4). A branch-scoped
 | role only sees its assigned branches' data; reaching an own-merchant branch it
 | is not assigned to yields 403 no_branch_scope (the branch IS visible to the
 | merchant, so this is an authority denial, not an existence leak).
 */

it('returns 403 no_branch_scope for an own-merchant branch the user is not assigned to', function (): void {
    [, $merchant] = activeAdmin();
    $assigned = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $other = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$fo] = branchStaff($merchant, $assigned, MerchantUserRole::FrontOffice);

    $this->actingAs($fo, 'sanctum')
        ->getJson("/api/v1/branches/{$other->ulid}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'no_branch_scope');
});

it('lists only the branch-scoped user assigned branches', function (): void {
    [, $merchant] = activeAdmin();
    $assigned = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    MerchantBranch::factory()->count(2)->create(['merchant_id' => $merchant->id]);
    [$fo] = branchStaff($merchant, $assigned, MerchantUserRole::FrontOffice);

    $this->actingAs($fo, 'sanctum')
        ->getJson('/api/v1/branches')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $assigned->ulid);
});

it('scopes the staff roster to the HR user own branch', function (): void {
    [, $merchant] = activeAdmin();
    $branchA = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $branchB = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branchA, MerchantUserRole::Hr);
    branchStaff($merchant, $branchA, MerchantUserRole::FrontOffice);
    branchStaff($merchant, $branchB, MerchantUserRole::FrontOffice);

    // HR in branch A sees only branch A staff (its own membership + the FO in A),
    // never the front-office member assigned to branch B.
    $this->actingAs($hr, 'sanctum')
        ->getJson('/api/v1/staff')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

it('lets a merchant admin reach every own-merchant branch', function (): void {
    [$admin, $merchant] = activeAdmin();
    $a = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $b = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $this->actingAs($admin, 'sanctum')->getJson("/api/v1/branches/{$a->ulid}")->assertStatus(200);
    $this->actingAs($admin, 'sanctum')->getJson("/api/v1/branches/{$b->ulid}")->assertStatus(200);
});
