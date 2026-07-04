<?php

declare(strict_types=1);

namespace App\Domain\Receipts\Jobs;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Services\GeneratedFileWriter;
use App\Domain\Receipts\Models\Receipt;
use App\Domain\Receipts\Services\ReceiptDocumentRenderer;
use App\Domain\Tenancy\Jobs\TenantAwareJob;

/**
 * Outbox-guaranteed receipt-PDF generation (Plan §43, §65, §67; Gate J; Phase 18B).
 *
 * Dispatched AFTER the validation transaction commits. It renders the receipt PDF and
 * writes it into the Phase 10F private file domain (purpose receipt_pdf), then sets
 * `receipts.file_id` + `file_generation_status = ready`. The receipt row already exists
 * (durable, `pending`) from the validation transaction, so the receipt can never appear
 * "issued for download" without a durable generation record: it is downloadable only
 * once this job flips it to `ready`. Idempotent — a receipt already `ready` is skipped,
 * so a retry cannot double-generate. On failure the status becomes `failed` and the job
 * is retried by the queue.
 */
final class GenerateReceiptPdf extends TenantAwareJob
{
    public int $tries = 5;

    public int $receiptId;

    public function __construct(int $receiptId, ?int $merchantId, ?int $branchId)
    {
        parent::__construct($merchantId, $branchId);
        $this->receiptId = $receiptId;
    }

    protected function handleWithinTenant(): void
    {
        /** @var Receipt|null $receipt */
        $receipt = Receipt::query()->find($this->receiptId);

        if ($receipt === null || $receipt->file_generation_status === 'ready') {
            return;
        }

        try {
            $bytes = app(ReceiptDocumentRenderer::class)->render($receipt);

            $file = app(GeneratedFileWriter::class)->write(
                FilePurpose::ReceiptPdf,
                $bytes,
                'receipt-'.$receipt->receipt_number.'.pdf',
                'application/pdf',
                'pdf',
                $receipt->merchant_id,
                $receipt->branch_id,
                null,
            );

            $receipt->forceFill([
                'file_id' => $file->id,
                'file_generation_status' => 'ready',
            ])->save();
        } catch (\Throwable $e) {
            $receipt->forceFill(['file_generation_status' => 'failed'])->save();

            throw $e;
        }
    }

    /** Permanent failure after retries: leave a redacted marker; never expose internals. */
    public function failed(\Throwable $e): void
    {
        $receipt = Receipt::query()->find($this->receiptId);
        $receipt?->forceFill(['file_generation_status' => 'failed'])->save();

        app(AuditRecorder::class)->record(
            AuditEvent::FileScanFailed,
            null,
            $this->tenantMerchantId,
            $this->tenantBranchId,
            $receipt,
            ['receipt_generation' => 'failed'],
        );
    }
}
