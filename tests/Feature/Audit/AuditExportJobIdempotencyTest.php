<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditExportStatus;
use App\Domain\Audit\Models\AuditExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('audit', 'audit-exports');

beforeEach(function (): void {
    Storage::fake((string) config('files.disk'));
});

it('is idempotent — re-running the generation job does not double-generate', function (): void {
    $export = AuditExport::factory()->create(); // queued

    runAuditExportJob($export);
    $fileId = $export->refresh()->file_id;
    expect($fileId)->not->toBeNull()->and($export->status)->toBe(AuditExportStatus::Ready);

    runAuditExportJob($export); // second run is a no-op (status no longer queued)
    expect($export->refresh()->file_id)->toBe($fileId)
        ->and($export->status)->toBe(AuditExportStatus::Ready);
});

it('skips generation for an export already in a terminal state', function (): void {
    $export = AuditExport::factory()->revoked()->create();
    $fileId = $export->file_id;

    runAuditExportJob($export); // must not resurrect a revoked export
    expect($export->refresh()->status)->toBe(AuditExportStatus::Revoked)
        ->and($export->file_id)->toBe($fileId);
});
