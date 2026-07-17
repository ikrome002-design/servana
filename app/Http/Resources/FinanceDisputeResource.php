<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\FinanceOps\Models\FinanceDispute;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Finance dispute payload (Plan §44; Phase 18B). Exposes the dispute ULID, status,
 * sanitised reason + resolution note, the linked invoice/payment-record public
 * references, and whether private evidence is attached (ULID only — never a storage
 * path). It NEVER exposes a sequential id or the storage path/signature of the evidence.
 *
 * @mixin FinanceDispute
 */
final class FinanceDisputeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'resolution_note' => $this->resolution_note,
            'has_evidence' => $this->evidence_file_id !== null,
            'created_at' => $this->created_at === null ? null : $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at === null ? null : $this->updated_at->toIso8601String(),
            'invoice' => $this->whenLoaded('invoice', function (): ?array {
                /** @var Invoice|null $invoice */
                $invoice = $this->invoice;

                return $invoice === null ? null : ['id' => $invoice->ulid, 'invoice_number' => $invoice->invoice_number];
            }),
            'payment_record' => $this->whenLoaded('paymentRecord', function (): ?array {
                /** @var PaymentRecord|null $record */
                $record = $this->paymentRecord;

                return $record === null ? null : ['id' => $record->ulid, 'method' => $record->method->value];
            }),
        ];
    }
}
