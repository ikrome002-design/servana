<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditExportStatus;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('audit', 'audit-exports');

/*
 | Phase 19 (ADR-010): the audit_exports DB invariants — branch-owned, backed status
 | CHECK, reason non-empty, scope_json object, download coherence, row_count/state
 | requirements, file RESTRICT, no soft delete, unique ULID route key.
 */

/** An Audit user assigned to a fresh branch, plus the branch + merchant. */
function auditExportBranch(): array
{
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);

    return compact('admin', 'merchant', 'branch', 'audit');
}

it('is branch-owned with a ULID route key and no soft-delete column', function (): void {
    $export = AuditExport::factory()->create();

    expect($export->branch_id)->not->toBeNull()
        ->and($export->merchant_id)->not->toBeNull()
        ->and($export->getRouteKeyName())->toBe('ulid')
        ->and(strlen($export->ulid))->toBe(26);

    expect(DB::getSchemaBuilder()->hasColumn('audit_exports', 'deleted_at'))->toBeFalse();
});

it('rejects a status outside the lifecycle CHECK', function (): void {
    $export = AuditExport::factory()->create();

    expect(fn () => DB::table('audit_exports')->where('id', $export->id)->update(['status' => 'archived']))
        ->toThrow(QueryException::class);
});

it('rejects an empty reason', function (): void {
    expect(fn () => AuditExport::factory()->create(['reason' => '   ']))
        ->toThrow(QueryException::class);
});

it('rejects a non-object scope_json', function (): void {
    expect(fn () => DB::table('audit_exports')->insert([
        'ulid' => (string) Str::ulid(),
        'merchant_id' => auditExportBranch()['merchant']->id,
        'branch_id' => MerchantBranch::factory()->create()->id,
        'requested_by_user_id' => User::factory()->create()->id,
        'reason' => 'x-reason',
        'scope_json' => json_encode(['a', 'b']), // array, not object
        'status' => 'queued',
        'download_count' => 0,
        'requested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces download-count / timestamp coherence', function (): void {
    $export = AuditExport::factory()->ready()->create();

    // download_count > 0 requires first/last set — violate it.
    expect(fn () => DB::table('audit_exports')->where('id', $export->id)
        ->update(['download_count' => 2, 'first_downloaded_at' => null, 'last_downloaded_at' => null]))
        ->toThrow(QueryException::class);
});

it('requires file_id + generated_at + expires_at + row_count for a ready export', function (): void {
    $export = AuditExport::factory()->create(); // queued

    expect(fn () => DB::table('audit_exports')->where('id', $export->id)
        ->update(['status' => AuditExportStatus::Ready->value])) // ready without file/row_count
        ->toThrow(QueryException::class);
});

it('refuses to drop a referenced uploaded_files row (RESTRICT)', function (): void {
    $export = AuditExport::factory()->ready()->create();

    expect(fn () => DB::table('uploaded_files')->where('id', $export->file_id)->delete())
        ->toThrow(QueryException::class);
});

it('generates a unique ULID public identifier', function (): void {
    $a = AuditExport::factory()->create();
    $b = AuditExport::factory()->create();

    expect($a->ulid)->not->toBe($b->ulid);
});
