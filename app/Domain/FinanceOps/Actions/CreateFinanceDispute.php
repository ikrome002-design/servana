<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Enums\FinanceDisputeStatus;
use App\Domain\FinanceOps\Exceptions\FinanceDisputeException;
use App\Domain\FinanceOps\Models\FinanceDispute;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Open a finance dispute over an invoice and/or a payment record (Plan §44; Phase 18B).
 * Finance-only. The dispute NEVER mutates the disputed source record; it links to it and
 * carries a mandatory reason and optional private Phase 10F evidence. Merchant/branch are
 * derived from the linked record (never the request body).
 */
final class CreateFinanceDispute
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(User $creator, ?Invoice $invoice, ?PaymentRecord $paymentRecord, string $reason, ?int $evidenceFileId): FinanceDispute
    {
        if ($invoice === null && $paymentRecord === null) {
            throw FinanceDisputeException::linkageRequired();
        }

        $anchor = $invoice ?? $paymentRecord;

        return DB::transaction(function () use ($creator, $invoice, $paymentRecord, $reason, $evidenceFileId, $anchor): FinanceDispute {
            $dispute = FinanceDispute::create([
                'merchant_id' => $anchor->merchant_id,
                'branch_id' => $anchor->branch_id,
                'invoice_id' => $invoice?->id,
                'payment_record_id' => $paymentRecord?->id,
                'status' => FinanceDisputeStatus::Open,
                'reason' => $reason,
                'resolution_note' => null,
                'evidence_file_id' => $evidenceFileId,
                'created_by' => $creator->id,
                'resolved_by' => null,
            ]);

            $this->audit->record(AuditEvent::FinanceDisputeOpened, $creator, $dispute->merchant_id, $dispute->branch_id, $dispute, [
                'dispute_id' => $dispute->ulid,
                'invoice_id' => $invoice?->ulid,
                'payment_record_id' => $paymentRecord?->ulid,
                'reason' => $reason,
            ]);

            return $dispute;
        });
    }
}
