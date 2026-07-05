<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('audit', 'audit-exports', 'isolation');

beforeEach(function (): void {
    Storage::fake((string) config('files.disk'));
});

it('404s a foreign-tenant export ULID without leaking it', function (): void {
    $scn = auditExportScenario();
    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');

    $other = auditExportScenario();
    test()->actingAs($other['audit'], 'sanctum')->getJson("/api/v1/audit-exports/{$ulid}")->assertNotFound();
    test()->actingAs($other['audit'], 'sanctum')->postJson("/api/v1/audit-exports/{$ulid}/download-link")->assertNotFound();
    test()->actingAs($other['audit'], 'sanctum')->postJson("/api/v1/audit-exports/{$ulid}/revoke")->assertNotFound();
});

it('denies a same-tenant Audit user reading an export for a branch they are not assigned to', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branchA = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $branchB = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$auditA] = branchStaff($merchant, $branchA, MerchantUserRole::Audit);
    [$auditB] = branchStaff($merchant, $branchB, MerchantUserRole::Audit);

    $ulid = (string) requestAuditExport($auditA, ['branch' => $branchA->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');

    // Audit user assigned to branch B cannot see branch A's export: the same-merchant
    // binding resolves, then AuditExportPolicy's branch scope denies (403 — the
    // established same-tenant wrong-branch posture).
    test()->actingAs($auditB, 'sanctum')->getJson("/api/v1/audit-exports/{$ulid}")->assertForbidden();
});

it('never includes merchant-level (branch_id null) audit rows in a branch export', function (): void {
    $scn = auditExportScenario();

    // A merchant-level (branch_id null) structural row that must NEVER be exported.
    app(AuditRecorder::class)->record(AuditEvent::PermissionOverrideCreated, $scn['admin'], $scn['merchant']->id);

    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();
    runAuditExportJob($export);

    $csv = Storage::disk((string) config('files.disk'))->get((string) $export->refresh()->file?->final_path);

    expect($csv)->not->toContain('permission.override.created')
        ->and($csv)->toContain('branch.day_opened'); // the branch rows ARE present
});
