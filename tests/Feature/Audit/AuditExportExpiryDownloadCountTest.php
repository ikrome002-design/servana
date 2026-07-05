<?php

declare(strict_types=1);

use App\Domain\Audit\Actions\ExpireAuditExport;
use App\Domain\Audit\Actions\RevokeAuditExport;
use App\Domain\Audit\Models\AuditExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('audit', 'audit-exports');

beforeEach(function (): void {
    Storage::fake((string) config('files.disk'));
});

it('counts each authorized stream exactly once and keeps first_downloaded_at immutable', function (): void {
    $scn = auditExportScenario();
    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();
    runAuditExportJob($export);

    // Link issuance alone never increments.
    test()->actingAs($scn['audit'], 'sanctum')->postJson("/api/v1/audit-exports/{$ulid}/download-link")->assertOk();
    expect($export->refresh()->download_count)->toBe(0);

    streamAuditExport($scn['audit'], $ulid)->assertOk();
    $first = $export->refresh()->first_downloaded_at;
    expect($export->download_count)->toBe(1)->and($first)->not->toBeNull();

    streamAuditExport($scn['audit'], $ulid)->assertOk();
    streamAuditExport($scn['audit'], $ulid)->assertOk();
    $export->refresh();
    expect($export->download_count)->toBe(3)
        ->and($export->first_downloaded_at->equalTo($first))->toBeTrue()
        ->and($export->last_downloaded_at->greaterThanOrEqualTo($first))->toBeTrue();
});

it('refuses to stream an expired export', function (): void {
    $scn = auditExportScenario();
    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();
    runAuditExportJob($export);

    app(ExpireAuditExport::class)->handle($export);
    streamAuditExport($scn['audit'], $ulid)->assertStatus(409);
    expect($export->refresh()->download_count)->toBe(0);
});

it('refuses to stream a revoked export', function (): void {
    $scn = auditExportScenario();
    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();
    runAuditExportJob($export);

    app(RevokeAuditExport::class)->handle($export, $scn['audit']);
    streamAuditExport($scn['audit'], $ulid)->assertStatus(409);
});
