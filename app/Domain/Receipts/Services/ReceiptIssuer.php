<?php

declare(strict_types=1);

namespace App\Domain\Receipts\Services;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Domain\Receipts\Models\Receipt;

/**
 * Issues the ONE original receipt for a validated payment group (Plan §43; Gate J;
 * Phase 18B). Called INSIDE the validation transaction, AFTER the validation event is
 * created. Allocates a gap-free per-merchant receipt number
 * ({@see ReceiptNumberAllocator}), snapshots the SAFE component data
 * ({method, amount_minor} only — never a reference/id/path), and creates a durable
 * `receipts` row with `file_generation_status = pending`. The PDF is produced by an
 * outbox-guaranteed job after commit; the receipt is not downloadable until `ready`.
 */
class ReceiptIssuer
{
    public function __construct(private readonly ReceiptNumberAllocator $numbers) {}

    /**
     * @param  list<PaymentRecord>  $components
     */
    public function issueOriginal(Invoice $invoice, PaymentValidationEvent $event, array $components): Receipt
    {
        $number = $this->numbers->allocate($invoice->merchant_id);

        return Receipt::create([
            'merchant_id' => $invoice->merchant_id,
            'branch_id' => $invoice->branch_id,
            'invoice_id' => $invoice->id,
            'payment_validation_event_id' => $event->id,
            'receipt_number' => $number,
            'amount_minor' => (int) $event->validated_amount_minor,
            'currency' => $invoice->currency,
            'components' => $this->snapshot($components),
            'reissue_of_receipt_id' => null,
            'reason' => null,
            'file_id' => null,
            'file_generation_status' => 'pending',
            'issued_by' => null,
        ]);
    }

    /**
     * Safe per-component snapshot — method + integer minor amount only.
     *
     * @param  list<PaymentRecord>  $components
     * @return list<array{method: string, amount_minor: int}>
     */
    private function snapshot(array $components): array
    {
        return array_map(static fn (PaymentRecord $c): array => [
            'method' => $c->method->value,
            'amount_minor' => (int) ($c->validated_amount_minor ?? $c->amount_minor),
        ], $components);
    }
}
