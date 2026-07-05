<?php

declare(strict_types=1);

namespace App\Domain\Audit\Jobs;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditExportStatus;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Audit\Services\AuditExportCsvBuilder;
use App\Domain\Audit\Services\AuditExportStateMachine;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\FilePurposeRegistry;
use App\Domain\Files\Services\GeneratedFileWriter;
use App\Domain\Tenancy\Jobs\TenantAwareJob;
use Carbon\CarbonImmutable;

/**
 * Async, tenant-aware Audit-export generation (Plan §13.5, §80; Phase 19; ADR-010).
 * Runs on `reports-exports`. Idempotent: an export not in `queued` is skipped, so a
 * retry cannot double-generate. Builds the MASKED, merchant + branch SCOPED CSV via
 * {@see AuditExportCsvBuilder} (scope IN the query, bounded chunks, never branch-null
 * rows), writes it through the Phase 10F file domain ({@see GeneratedFileWriter},
 * FilePurpose::AuditExport), and marks the export `ready` with `expires_at`. On failure
 * only a redacted code/message is stored. Emits `audit_export.generated` / `.failed`
 * (never the contents, path, or signature).
 */
final class GenerateAuditExport extends TenantAwareJob
{
    public int $tries = 3;

    public int $exportId;

    public function __construct(int $exportId, ?int $merchantId, ?int $branchId)
    {
        parent::__construct($merchantId, $branchId);
        $this->exportId = $exportId;
    }

    protected function handleWithinTenant(): void
    {
        /** @var AuditExport|null $export */
        $export = AuditExport::query()->find($this->exportId);

        if ($export === null || $export->status !== AuditExportStatus::Queued) {
            return; // idempotent: already processing/ready/terminal
        }

        app(AuditExportStateMachine::class)->ensure($export->status, AuditExportStatus::Processing);
        $export->forceFill([
            'status' => AuditExportStatus::Processing->value,
            'processing_started_at' => now(),
        ])->save();

        try {
            [$csv, $rowCount] = app(AuditExportCsvBuilder::class)->build($export);

            $file = app(GeneratedFileWriter::class)->write(
                FilePurpose::AuditExport,
                $csv,
                'audit-export-'.$export->ulid.'.csv',
                'text/csv',
                'csv',
                $export->merchant_id,
                $export->branch_id,
                $export->requested_by_user_id,
            );

            $retentionDays = FilePurposeRegistry::for(FilePurpose::AuditExport)->retentionDays ?? 30;

            $export->forceFill([
                'status' => AuditExportStatus::Ready->value,
                'file_id' => $file->id,
                'row_count' => $rowCount,
                'generated_at' => now(),
                'expires_at' => CarbonImmutable::now()->addDays($retentionDays),
            ])->save();

            app(AuditRecorder::class)->record(AuditEvent::AuditExportGenerated, null, $export->merchant_id, $export->branch_id, $export, [
                'export_id' => $export->ulid,
                'row_count' => $rowCount,
            ]);
        } catch (\Throwable $e) {
            $export->forceFill([
                'status' => AuditExportStatus::Failed->value,
                'failed_at' => now(),
                'failure_code' => 'generation_failed',
                'failure_message_redacted' => 'Audit export generation failed.',
            ])->save();

            app(AuditRecorder::class)->record(AuditEvent::AuditExportFailed, null, $export->merchant_id, $export->branch_id, $export, [
                'export_id' => $export->ulid,
                'failure_code' => 'generation_failed',
            ]);
        }
    }
}
