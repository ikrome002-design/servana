<?php

declare(strict_types=1);

use App\Domain\Audit\Actions\ExpireAuditExport;
use App\Domain\Audit\Enums\AuditExportStatus;
use App\Domain\Audit\Exceptions\AuditExportException;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Audit\Services\AuditExportStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('audit', 'audit-exports');

beforeEach(function (): void {
    Storage::fake((string) config('files.disk'));
});

// auditExportScenario / requestAuditExport / runAuditExportJob / streamAuditExport
// live in tests/Pest.php so every parallel worker sees them (a file-local Pest
// helper is invisible to workers running the other audit-export files).

it('runs request → queued → generated (ready) → stream download with accounting', function (): void {
    $scn = auditExportScenario();

    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Quarterly branch audit review.'])
        ->assertCreated()->assertJsonPath('data.status', 'queued')->json('data.id');

    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();
    expect($export->requested_at)->not->toBeNull();

    runAuditExportJob($export); // idempotent — a no-op if the afterCommit job already ran
    $export->refresh();
    expect($export->status)->toBe(AuditExportStatus::Ready)
        ->and($export->file_id)->not->toBeNull()
        ->and($export->row_count)->toBe(3) // 2 seeded branch events + the audit_export.requested event
        ->and($export->generated_at)->not->toBeNull()
        ->and($export->expires_at)->not->toBeNull();

    // Link issuance does NOT increment the counter (accounting is on the stream).
    test()->actingAs($scn['audit'], 'sanctum')->postJson("/api/v1/audit-exports/{$ulid}/download-link")->assertOk();
    expect($export->refresh()->download_count)->toBe(0);

    // The stream increments exactly once and sets first/last.
    streamAuditExport($scn['audit'], $ulid)->assertOk();
    $export->refresh();
    expect($export->download_count)->toBe(1)
        ->and($export->first_downloaded_at)->not->toBeNull()
        ->and($export->last_downloaded_at)->not->toBeNull();

    $firstAt = $export->first_downloaded_at;
    streamAuditExport($scn['audit'], $ulid)->assertOk();
    $export->refresh();
    expect($export->download_count)->toBe(2)
        ->and($export->first_downloaded_at->equalTo($firstAt))->toBeTrue()   // first unchanged
        ->and($export->last_downloaded_at->greaterThanOrEqualTo($firstAt))->toBeTrue();
});

it('revokes a ready export so it can no longer stream (409)', function (): void {
    $scn = auditExportScenario();
    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();
    runAuditExportJob($export);

    test()->actingAs($scn['audit'], 'sanctum')->postJson("/api/v1/audit-exports/{$ulid}/revoke")
        ->assertOk()->assertJsonPath('data.status', 'revoked');

    expect($export->refresh()->revoked_at)->not->toBeNull();
    streamAuditExport($scn['audit'], $ulid)->assertStatus(409);
    test()->actingAs($scn['audit'], 'sanctum')->postJson("/api/v1/audit-exports/{$ulid}/download-link")->assertStatus(409);
});

it('expires a ready export so it can no longer stream (409)', function (): void {
    $scn = auditExportScenario();
    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();
    runAuditExportJob($export);

    app(ExpireAuditExport::class)->handle($export);
    expect($export->refresh()->status)->toBe(AuditExportStatus::Expired);

    streamAuditExport($scn['audit'], $ulid)->assertStatus(409);
});

it('rejects an invalid lifecycle transition with 422 invalid_state_transition', function (): void {
    $export = AuditExport::factory()->create(); // queued
    // queued cannot go straight to revoked.
    expect(fn () => app(AuditExportStateMachine::class)
        ->ensure(AuditExportStatus::Queued, AuditExportStatus::Revoked))
        ->toThrow(AuditExportException::class);
    unset($export);
});
