<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Jobs;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\FilePurposeRegistry;
use App\Domain\Files\Services\GeneratedFileWriter;
use App\Domain\FinanceOps\Enums\FinanceExportStatus;
use App\Domain\FinanceOps\Models\FinanceExport;
use App\Domain\FinanceOps\Services\FinanceExportCsvBuilder;
use App\Domain\FinanceOps\Services\FinanceExportStateMachine;
use App\Domain\Tenancy\Jobs\TenantAwareJob;
use Carbon\CarbonImmutable;

/**
 * Async, tenant-aware finance-export generation (Plan §65, §67; Gate I; Phase 18B).
 * Runs on `reports-exports`. Idempotent: an export not in `queued` is skipped, so a
 * retry cannot double-generate. Builds the MASKED, merchant + optional-branch SCOPED
 * CSV via {@see FinanceExportCsvBuilder} (scope applied IN the query, bounded chunks),
 * writes it through the Phase 10F file domain ({@see GeneratedFileWriter},
 * FilePurpose::FinanceExport — never a direct controller write), and marks the export
 * `ready` with `expires_at`. On failure only a redacted code/message is stored. Emits
 * `finance_export.generated` / `.failed` (never the contents, path, or signature).
 */
final class GenerateFinanceExport extends TenantAwareJob
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
        /** @var FinanceExport|null $export */
        $export = FinanceExport::query()->find($this->exportId);

        if ($export === null || $export->status !== FinanceExportStatus::Queued) {
            return; // idempotent: already processing/ready/terminal
        }

        app(FinanceExportStateMachine::class)->ensure($export->status, FinanceExportStatus::Processing);
        $export->forceFill(['status' => FinanceExportStatus::Processing->value])->save();

        try {
            [$csv, $rowCount] = app(FinanceExportCsvBuilder::class)->build($export);

            $file = app(GeneratedFileWriter::class)->write(
                FilePurpose::FinanceExport,
                $csv,
                'finance-export-'.$export->export_type->value.'-'.$export->ulid.'.csv',
                'text/csv',
                'csv',
                $export->merchant_id,
                $export->branch_id,
                $export->requested_by,
            );

            $retentionDays = FilePurposeRegistry::for(FilePurpose::FinanceExport)->retentionDays ?? 30;

            $export->forceFill([
                'status' => FinanceExportStatus::Ready->value,
                'file_id' => $file->id,
                'row_count' => $rowCount,
                'expires_at' => CarbonImmutable::now()->addDays($retentionDays),
            ])->save();

            app(AuditRecorder::class)->record(AuditEvent::FinanceExportGenerated, null, $export->merchant_id, $export->branch_id, $export, [
                'export_id' => $export->ulid,
                'export_type' => $export->export_type->value,
                'row_count' => $rowCount,
            ]);
        } catch (\Throwable $e) {
            $export->forceFill([
                'status' => FinanceExportStatus::Failed->value,
                'failure_code' => 'generation_failed',
                'failure_message_redacted' => 'Finance export generation failed.',
            ])->save();

            app(AuditRecorder::class)->record(AuditEvent::FinanceExportFailed, null, $export->merchant_id, $export->branch_id, $export, [
                'export_id' => $export->ulid,
                'failure_code' => 'generation_failed',
            ]);
        }
    }
}
