<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Services\StaffLifecycleService;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'isolation', 'security');

/*
 | Branch-assignment freshness (Plan §79 R6, §8.2). A branch-scoped role's active
 | branch ids are re-resolved every request, so revoking the assignment denies the
 | next branch-scoped request — without any logout or session change.
 */

it('denies the next branch request after the assignment is revoked mid-session', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$staffUser, $membership] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);

    // Assigned: the scoped read resolves.
    $this->actingAs($staffUser, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}")
        ->assertStatus(200);

    // Revoke the branch assignment.
    $assignment = $membership->branchAssignments()->active()->firstOrFail();
    app(StaffLifecycleService::class)->revokeBranchAssignment($assignment);

    // Next request: no active assignment → 403 no_branch_scope (re-resolved).
    $this->actingAs($staffUser->fresh(), 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'no_branch_scope');
});

it('keeps a Merchant Admin (all-branch) unaffected by branch-assignment changes', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    // Admin sees all own-merchant branches by role, no assignment row needed.
    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/branches/{$branch->ulid}")
        ->assertStatus(200);
});
