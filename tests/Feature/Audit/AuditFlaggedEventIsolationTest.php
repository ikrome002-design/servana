<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('audit');

/*
 | Phase 19 — flagged-event tenant/branch isolation + permission gating. Cross-tenant ULIDs
 | never enumerate (404); same-merchant wrong-branch rows are filtered by the branch scope
 | (404); non-Audit roles are denied; flagging a non-accessible audit row is denied.
 */

it('404s a foreign-tenant flagged event without enumeration', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);

    $foreignMerchant = Merchant::factory()->active()->create();
    $foreignBranch = MerchantBranch::factory()->create(['merchant_id' => $foreignMerchant->id]);
    $foreignLog = AuditLog::factory()->create(['merchant_id' => $foreignMerchant->id, 'branch_id' => $foreignBranch->id]);
    $foreign = AuditFlaggedEvent::factory()->create([
        'merchant_id' => $foreignMerchant->id, 'branch_id' => $foreignBranch->id,
        'audit_log_id' => $foreignLog->id, 'created_by' => User::factory(),
    ]);

    test()->actingAs($audit, 'sanctum')->getJson("/api/v1/audit-flagged-events/{$foreign->ulid}")->assertNotFound();
    test()->actingAs($audit, 'sanctum')->postJson("/api/v1/audit-flagged-events/{$foreign->ulid}/start-review")->assertNotFound();
});

it('does not surface a same-merchant flagged event from an unassigned branch', function (): void {
    [, $merchant] = activeAdmin();
    $branchA = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $branchB = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branchA, MerchantUserRole::Audit); // assigned to A only

    $logB = AuditLog::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branchB->id]);
    $flagB = AuditFlaggedEvent::factory()->create([
        'merchant_id' => $merchant->id, 'branch_id' => $branchB->id,
        'audit_log_id' => $logB->id, 'created_by' => User::factory(),
    ]);

    // The assigned-branch queue must never include another branch's flag (BranchScope).
    test()->actingAs($audit, 'sanctum')->getJson('/api/v1/audit-flagged-events')->assertOk()->assertJsonCount(0, 'data');
    // Directly addressing the other branch's flag is denied (404 scope-filter or 403 policy).
    $status = test()->actingAs($audit, 'sanctum')->getJson("/api/v1/audit-flagged-events/{$flagB->ulid}")->getStatusCode();
    expect($status)->toBeIn([403, 404]);
});

it('denies a non-Audit role the flagged-event endpoints', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);
    $log = AuditLog::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);

    test()->actingAs($finance, 'sanctum')->getJson('/api/v1/audit-flagged-events')->assertForbidden();
    test()->actingAs($finance, 'sanctum')->postJson('/api/v1/audit-flagged-events', ['audit_log' => $log->ulid])->assertForbidden();
});

it('denies flagging an audit row from an unassigned branch', function (): void {
    [, $merchant] = activeAdmin();
    $branchA = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $branchB = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branchA, MerchantUserRole::Audit);
    $logB = AuditLog::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branchB->id]);

    test()->actingAs($audit, 'sanctum')->postJson('/api/v1/audit-flagged-events', ['audit_log' => $logB->ulid])
        ->assertForbidden();
});

it('404s flagging a foreign-tenant audit row', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);

    $foreignMerchant = Merchant::factory()->active()->create();
    $foreignBranch = MerchantBranch::factory()->create(['merchant_id' => $foreignMerchant->id]);
    $foreignLog = AuditLog::factory()->create(['merchant_id' => $foreignMerchant->id, 'branch_id' => $foreignBranch->id]);

    test()->actingAs($audit, 'sanctum')->postJson('/api/v1/audit-flagged-events', ['audit_log' => $foreignLog->ulid])
        ->assertNotFound();
});
