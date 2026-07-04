<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentValidationEvent;
use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Immutable group validation-decision payload (Plan §42; Phase 18B). Exposes the event
 * ULID, decision, validated integer minor-unit amount, sanitised reason, the validated
 * group (ULID + status), the invoice (ULID + number + derived payment state + balance),
 * and — for a validated decision — the issued original receipt (ULID + number). It
 * NEVER exposes a sequential id, a reference, a storage path, or a signed URL.
 *
 * @mixin PaymentValidationEvent
 */
final class PaymentValidationEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PaymentRecordingGroup|null $group */
        $group = $this->group;

        return [
            'id' => $this->ulid,
            'decision' => $this->decision->value,
            'validated_amount' => ($this->validated_amount_minor !== null && $group !== null)
                ? Money::ofMinor($this->validated_amount_minor, Currency::from($group->currency))->toArray()
                : null,
            'reason' => $this->reason,
            'created_at' => $this->created_at->toIso8601String(),
            'group' => $this->whenLoaded('group', function (): array {
                /** @var PaymentRecordingGroup $group */
                $group = $this->group;

                return ['id' => $group->ulid, 'status' => $group->status->value];
            }),
            'invoice' => $this->whenLoaded('invoice', function (): array {
                /** @var Invoice $invoice */
                $invoice = $this->invoice;

                return [
                    'id' => $invoice->ulid,
                    'invoice_number' => $invoice->invoice_number,
                    'status' => $invoice->status->value,
                    'validated_paid' => Money::ofMinor($invoice->validated_paid_minor, Currency::from($invoice->currency))->toArray(),
                    'total' => Money::ofMinor($invoice->total_minor, Currency::from($invoice->currency))->toArray(),
                ];
            }),
            'receipt' => $this->whenLoaded('receipt', fn (): ?array => $this->receipt === null
                ? null
                : ReceiptResource::make($this->receipt->loadMissing('invoice'))->toArray($request)),
        ];
    }
}
