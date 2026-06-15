<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('isolation');

/*
 | Branch route-binding isolation (Plan §8.2, §8.4). Foreign branch ULIDs must
 | 404 (never 403) so existence is not leaked; branch-scoped users without an
 | assignment to an own-merchant branch get 403 no_branch_scope.
 */

it('returns 404 for a branch ULID belonging to another merchant', function (): void {
    [$admin] = activeAdmin();
    $foreign = MerchantBranch::factory()->create([
        'merchant_id' => Merchant::factory()->active()->create()->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/branches/{$foreign->ulid}")
        ->assertStatus(404);
});

it('returns 404 for a non-existent branch ULID', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches/01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertStatus(404);
});

it('lets a merchant admin access any own-merchant branch', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}")
        ->assertStatus(200)
        ->assertJsonPath('data.id', $branch->ulid);
});

it('denies a branch-scoped user an own-merchant branch they are not assigned to', function (): void {
    [, $merchant] = activeAdmin();
    $assignedBranch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$fo] = branchStaff($merchant, $assignedBranch, MerchantUserRole::FrontOffice, assigned: true);

    $this->actingAs($fo, 'sanctum')
        ->getJson("/api/v1/branches/{$otherBranch->ulid}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'no_branch_scope');
});

it('lets a branch-scoped user access their assigned branch', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$fo] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice, assigned: true);

    $this->actingAs($fo, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}")
        ->assertStatus(200);
});
