<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('audit', 'isolation');

/*
 | R2: a branch-scoped Audit user reads ONLY its assigned branch's audit rows
 | (Scope §4.8). Merchant-wide and other-branch rows are not visible; cross-branch
 | show() is denied; a Merchant Admin (non-branch-scoped) sees all merchant rows.
 */

function seedBranchRows(): array
{
    [$admin, $merchant] = activeAdmin();
    $branchA = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $branchB = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $recorder = app(AuditRecorder::class);
    $rowA = $recorder->record(AuditEvent::BranchDayOpened, $admin, $merchant->id, $branchA->id, $branchA);
    $rowB = $recorder->record(AuditEvent::BranchDayOpened, $admin, $merchant->id, $branchB->id, $branchB);
    $rowMerchant = $recorder->record(AuditEvent::PermissionOverrideCreated, $admin, $merchant->id);

    // An Audit-role user assigned to branch A only.
    [$auditUser] = branchStaff($merchant, $branchA, MerchantUserRole::Audit);

    return compact('admin', 'auditUser', 'branchA', 'branchB', 'rowA', 'rowB', 'rowMerchant');
}

it('lists only the assigned branch rows for a branch-scoped Audit user', function (): void {
    ['auditUser' => $auditUser, 'rowA' => $rowA] = seedBranchRows();

    $ids = $this->actingAs($auditUser, 'sanctum')->getJson('/api/v1/audit-logs')
        ->assertStatus(200)->json('data.*.id');

    expect($ids)->toContain($rowA->ulid)->toHaveCount(1);
});

it('denies an Audit user reading another branch row', function (): void {
    ['auditUser' => $auditUser, 'rowB' => $rowB] = seedBranchRows();

    $this->actingAs($auditUser, 'sanctum')->getJson("/api/v1/audit-logs/{$rowB->ulid}")->assertStatus(403);
});

it('hides merchant-level rows from a branch-scoped Audit user', function (): void {
    ['auditUser' => $auditUser, 'rowMerchant' => $rowMerchant] = seedBranchRows();

    $this->actingAs($auditUser, 'sanctum')->getJson("/api/v1/audit-logs/{$rowMerchant->ulid}")->assertStatus(403);
});

it('lets a merchant admin see all own-merchant rows including merchant-level', function (): void {
    ['admin' => $admin, 'rowMerchant' => $rowMerchant] = seedBranchRows();

    $ids = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/audit-logs')
        ->assertStatus(200)->json('data.*.id');

    expect($ids)->toContain($rowMerchant->ulid);

    $this->actingAs($admin, 'sanctum')->getJson("/api/v1/audit-logs/{$rowMerchant->ulid}")->assertStatus(200);
});
