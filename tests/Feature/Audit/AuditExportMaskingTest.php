<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditExportStatus;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Audit\Services\AuditExportCsvBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('audit', 'audit-exports', 'security');

beforeEach(function (): void {
    Storage::fake((string) config('files.disk'));
});

it('permission-masks sensitive values in the generated CSV', function (): void {
    $scn = auditExportScenario();
    app(AuditRecorder::class)->record(AuditEvent::BranchProfileUpdated, $scn['admin'], $scn['merchant']->id, $scn['branch']->id, $scn['branch'], [
        'token' => 'supersecrettokenvalue',
        'reference' => 'REF-ABCD-9999',
        'email' => 'owner@salon.co.ke',
    ]);

    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();
    runAuditExportJob($export);

    $csv = Storage::disk((string) config('files.disk'))->get((string) $export->refresh()->file?->final_path);

    expect($csv)->toContain('[redacted]')                 // token redacted
        ->and($csv)->not->toContain('supersecrettokenvalue')
        ->and($csv)->not->toContain('REF-ABCD-9999')      // full reference never present
        ->and($csv)->not->toContain('owner@salon.co.ke'); // full email never present
});

it('never exposes the file id, storage path, signature, or an internal id in the resource', function (): void {
    $scn = auditExportScenario();
    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();
    runAuditExportJob($export);

    $response = test()->actingAs($scn['audit'], 'sanctum')->getJson("/api/v1/audit-exports/{$ulid}")->assertOk();
    $body = $response->getContent();
    $data = $response->json('data');

    expect($data['id'])->toBe($ulid)
        ->and((string) $data['id'])->not->toBe((string) $export->id);
    foreach (['file_id', 'final_path', 'storage_disk', 'quarantine_path', 'signature'] as $forbidden) {
        expect($data)->not->toHaveKey($forbidden);
    }
    expect($body)->not->toContain((string) $export->file?->final_path);
});

it('stores only a redacted failure code/message when generation throws', function (): void {
    $scn = auditExportScenario();
    $ulid = (string) requestAuditExport($scn['audit'], ['branch' => $scn['branch']->ulid, 'reason' => 'Review reason.'])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $ulid)->firstOrFail();

    // Reset to queued (the afterCommit sync job already produced a ready row) so the
    // mocked failure exercises the queued → processing → failed path deterministically.
    $export->forceFill([
        'status' => AuditExportStatus::Queued->value,
        'file_id' => null,
        'row_count' => null,
        'generated_at' => null,
        'expires_at' => null,
    ])->save();

    $this->mock(AuditExportCsvBuilder::class)
        ->shouldReceive('build')
        ->andThrow(new RuntimeException('SQLSTATE[XX000] secret path /var/private exploded'));

    runAuditExportJob($export);
    $export->refresh();

    expect($export->status)->toBe(AuditExportStatus::Failed)
        ->and($export->failed_at)->not->toBeNull()
        ->and($export->failure_code)->toBe('generation_failed')
        ->and($export->failure_message_redacted)->not->toContain('SQLSTATE')
        ->and($export->failure_message_redacted)->not->toContain('/var/private');
});
