<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Refunds\Models\Refund;
use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Refund payload (Plan §44; guardrail §6.4; Phase 18B). Exposes the refund ULID,
 * status, integer minor-unit amount + currency, method, MASKED external reference
 * suffix, sanitised reason, the invoice + component public references, and the
 * requester/approver/finalizer identities (ULID only). It NEVER exposes a sequential
 * id, the plaintext external reference, or client contact.
 *
 * @mixin Refund
 */
final class RefundResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = Currency::from($this->currency);

        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'amount' => Money::ofMinor($this->amount_minor, $currency)->toArray(),
            'currency' => $this->currency,
            'method' => $this->method->value,
            'reference_masked' => $this->maskedReference(),
            'reason' => $this->reason,
            'refund_group' => $this->refund_group_ulid,
            'approved_at' => $this->approved_at === null ? null : $this->approved_at->toIso8601String(),
            'finalized_at' => $this->finalized_at === null ? null : $this->finalized_at->toIso8601String(),
            'rejected_at' => $this->rejected_at === null ? null : $this->rejected_at->toIso8601String(),
            'created_at' => $this->created_at === null ? null : $this->created_at->toIso8601String(),
            'invoice' => $this->whenLoaded('invoice', function (): array {
                /** @var Invoice $invoice */
                $invoice = $this->invoice;

                return ['id' => $invoice->ulid, 'invoice_number' => $invoice->invoice_number, 'status' => $invoice->status->value];
            }),
            'payment_record' => $this->whenLoaded('paymentRecord', function (): ?array {
                /** @var PaymentRecord|null $record */
                $record = $this->paymentRecord;

                return $record === null ? null : ['id' => $record->ulid, 'method' => $record->method->value];
            }),
        ];
    }
}
