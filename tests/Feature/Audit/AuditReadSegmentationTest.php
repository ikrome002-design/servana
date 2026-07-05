<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('audit', 'permissions', 'authority');

/*
 | Phase 19 (canonical §19.2/§19.3): the merchant audit read API is DOMAIN-SEGMENTED.
 |   - /audit-logs              → general branch events   (audit.branch_events.view)
 |   - /audit-logs/finance      → finance-domain events   (finance.audit.view OR audit.finance.view)
 |   - /audit-logs/compensation → compensation-domain     (audit.compensation.view; empty until 20F–20H)
 | branch_events EXCLUDES finance/compensation rows; merchant-level (branch_id null)
 | rows are never exposed. `audit.view_full` is retired: MA/BM/HR have no audit key.
 */

/** @return array{admin: User, merchant: Merchant, branch: MerchantBranch, general: AuditLog, finance: AuditLog} */
function segmentScenario(): array
{
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $recorder = app(AuditRecorder::class);

    $general = $recorder->record(AuditEvent::BranchDayOpened, $admin, $merchant->id, $branch->id, $branch);
    $finance = $recorder->record(AuditEvent::InvoiceCreated, $admin, $merchant->id, $branch->id, $branch, ['invoice_number' => 'INV-1']);

    return compact('admin', 'merchant', 'branch', 'general', 'finance');
}

it('segments branch events from the finance domain for the Audit role', function (): void {
    $scn = segmentScenario();
    [$audit] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Audit);

    // branch_events includes the general row but EXCLUDES the finance row.
    $branchIds = $this->actingAs($audit, 'sanctum')->getJson('/api/v1/audit-logs')
        ->assertOk()->json('data.*.id');
    expect($branchIds)->toContain($scn['general']->ulid)->not->toContain($scn['finance']->ulid);

    // the finance surface returns the finance row and NOT the general one.
    $financeIds = $this->actingAs($audit, 'sanctum')->getJson('/api/v1/audit-logs/finance')
        ->assertOk()->json('data.*.id');
    expect($financeIds)->toContain($scn['finance']->ulid)->not->toContain($scn['general']->ulid);
});

it('returns an empty compensation surface until its owning phases (authorized, not denied)', function (): void {
    $scn = segmentScenario();
    [$audit] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Audit);

    $this->actingAs($audit, 'sanctum')->getJson('/api/v1/audit-logs/compensation')
        ->assertOk()->assertJsonCount(0, 'data');
});

it('lets Finance read the finance audit surface but not the branch-events or compensation surfaces', function (): void {
    $scn = segmentScenario();
    [$finance] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Finance);

    $ids = $this->actingAs($finance, 'sanctum')->getJson('/api/v1/audit-logs/finance')
        ->assertOk()->json('data.*.id');
    expect($ids)->toContain($scn['finance']->ulid);

    // finance.audit.view does NOT grant the branch-events or compensation segments.
    $this->actingAs($finance, 'sanctum')->getJson('/api/v1/audit-logs')->assertForbidden();
    $this->actingAs($finance, 'sanctum')->getJson('/api/v1/audit-logs/compensation')->assertForbidden();
});

it('denies Merchant Admin, Branch Manager, and HR every audit read surface (audit.view_full retired)', function (): void {
    $scn = segmentScenario();
    $branch = $scn['branch'];
    $merchant = $scn['merchant'];

    $surfaces = ['/api/v1/audit-logs', '/api/v1/audit-logs/finance', '/api/v1/audit-logs/compensation'];

    // Merchant Admin (non-branch-scoped) — no audit key at all.
    foreach ($surfaces as $url) {
        $this->actingAs($scn['admin'], 'sanctum')->getJson($url)->assertForbidden();
    }

    foreach ([MerchantUserRole::BranchManager, MerchantUserRole::Hr, MerchantUserRole::FrontOffice] as $role) {
        [$user] = branchStaff($merchant, $branch, $role);
        foreach ($surfaces as $url) {
            $this->actingAs($user, 'sanctum')->getJson($url)->assertForbidden();
        }
    }
});

it('confines the finance surface to the caller assigned branch and hides other-branch finance rows', function (): void {
    $scn = segmentScenario();
    $other = MerchantBranch::factory()->create(['merchant_id' => $scn['merchant']->id]);
    $otherFinance = app(AuditRecorder::class)->record(
        AuditEvent::InvoiceCreated, $scn['admin'], $scn['merchant']->id, $other->id, $other, ['invoice_number' => 'INV-2'],
    );

    // Audit assigned to the FIRST branch only.
    [$audit] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Audit);

    $ids = $this->actingAs($audit, 'sanctum')->getJson('/api/v1/audit-logs/finance')
        ->assertOk()->json('data.*.id');
    expect($ids)->toContain($scn['finance']->ulid)->not->toContain($otherFinance->ulid);
});
