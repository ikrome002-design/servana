<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('audit', 'isolation');

/*
 | Phase 19 (canonical §19.2/§19.3 closure): a branch-scoped Audit user reads ONLY
 | its actively-assigned branch's audit rows via `audit.branch_events.view`. Other-
 | branch rows and merchant-level (branch_id null) rows are never visible (Phase 19
 | Q2 — merchant-level rows are governance-scoped only). The legacy `audit.view_full`
 | key is retired: Merchant Admin has NO direct raw audit-log access.
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

it('excludes merchant-level (branch_id null) rows from a branch-scoped Audit user', function (): void {
    ['auditUser' => $auditUser, 'rowMerchant' => $rowMerchant] = seedBranchRows();

    // Not present in the branch-events list…
    $ids = $this->actingAs($auditUser, 'sanctum')->getJson('/api/v1/audit-logs')
        ->assertStatus(200)->json('data.*.id');
    expect($ids)->not->toContain($rowMerchant->ulid);

    // …and not addressable directly (Phase 19 Q2 — governance-scoped only).
    $this->actingAs($auditUser, 'sanctum')->getJson("/api/v1/audit-logs/{$rowMerchant->ulid}")->assertStatus(403);
});

it('denies a Merchant Admin any direct raw audit-log access (audit.view_full retired)', function (): void {
    ['admin' => $admin, 'rowMerchant' => $rowMerchant] = seedBranchRows();

    // No canonical audit read key → 403 at the permission middleware on every surface.
    $this->actingAs($admin, 'sanctum')->getJson('/api/v1/audit-logs')->assertStatus(403);
    $this->actingAs($admin, 'sanctum')->getJson("/api/v1/audit-logs/{$rowMerchant->ulid}")->assertStatus(403);
});
