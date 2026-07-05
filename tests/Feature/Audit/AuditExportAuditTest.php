<?php

declare(strict_types=1);

use App\Domain\Audit\Actions\ExpireAuditExport;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('audit', 'audit-exports');

beforeEach(function (): void {
    Storage::fake((string) config('files.disk'));
});

it('emits the typed lifecycle chain requested → generated → downloaded → revoked', function (): void {
    $scn = auditExportScenario();
    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();
    runAuditExportJob($export);

    streamAuditExport($scn['audit'], $ulid)->assertOk();
    test()->actingAs($scn['audit'], 'sanctum')->postJson("/api/v1/audit-exports/{$ulid}/revoke")->assertOk();

    $actions = AuditLog::query()->pluck('action')->all();
    expect($actions)->toContain(AuditEvent::AuditExportRequested->value)
        ->and($actions)->toContain(AuditEvent::AuditExportGenerated->value)
        ->and($actions)->toContain(AuditEvent::AuditExportDownloaded->value)
        ->and($actions)->toContain(AuditEvent::AuditExportRevoked->value);
});

it('emits audit_export.expired when a ready export expires', function (): void {
    $scn = auditExportScenario();
    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();
    runAuditExportJob($export);

    app(ExpireAuditExport::class)->handle($export);

    expect(AuditLog::query()->where('action', AuditEvent::AuditExportExpired->value)->exists())->toBeTrue();
});

it('records the export audit rows with merchant + branch attribution and no leak', function (): void {
    $scn = auditExportScenario();
    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');

    $requested = AuditLog::query()->where('action', AuditEvent::AuditExportRequested->value)->latest('id')->firstOrFail();
    expect($requested->merchant_id)->toBe($scn['merchant']->id)
        ->and($requested->branch_id)->toBe($scn['branch']->id);
    // Context carries only a safe scope summary, never the raw filters or a path.
    expect(json_encode($requested->context))->not->toContain('final_path')
        ->and($requested->context['export_id'])->toBe($ulid);
});
