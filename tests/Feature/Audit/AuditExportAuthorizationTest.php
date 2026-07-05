<?php

declare(strict_types=1);

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('audit', 'audit-exports', 'authority');

/*
 | Phase 19 (ADR-010): only the Audit role holds `audit.export`. Every other role is
 | denied at the permission middleware (before step-up), on both read and write surfaces.
 | A missing reason 422s; an unassigned branch is denied.
 */

it('denies every non-Audit role the audit-export endpoints', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    foreach ([
        MerchantUserRole::BranchManager,
        MerchantUserRole::Hr,
        MerchantUserRole::Finance,
        MerchantUserRole::FrontOffice,
        MerchantUserRole::Personnel,
    ] as $role) {
        [$user] = branchStaff($merchant, $branch, $role);

        test()->actingAs($user, 'sanctum')->getJson('/api/v1/audit-exports')->assertForbidden();
        test()->statefulMfa(now()->getTimestamp())->actingAs($user, 'sanctum')
            ->postJson('/api/v1/audit-exports', ['branch' => $branch->ulid, 'reason' => 'x-reason'])
            ->assertForbidden();
    }
});

it('requires a non-empty reason', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);

    test()->statefulMfa(now()->getTimestamp())->actingAs($audit, 'sanctum')
        ->postJson('/api/v1/audit-exports', ['branch' => $branch->ulid])
        ->assertStatus(422);
});

it('denies requesting an export for a branch the Audit user is not assigned to', function (): void {
    [, $merchant] = activeAdmin();
    $assigned = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $other = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $assigned, MerchantUserRole::Audit);

    // Same tenant, but the Audit user has no assignment to $other → no_branch_scope (403).
    test()->statefulMfa(now()->getTimestamp())->actingAs($audit, 'sanctum')
        ->postJson('/api/v1/audit-exports', ['branch' => $other->ulid, 'reason' => 'Review reason.'])
        ->assertForbidden();
});
