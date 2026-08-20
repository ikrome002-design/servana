<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Receipts\Models\Receipt;
use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Maker-safe payment lifecycle projection. No component references, checker
 * identity/reasons or Finance capabilities are exposed.
 *
 * @mixin PaymentRecordingGroup
 */
final class FrontOfficePaymentStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->invoice;
        /** @var Receipt|null $receipt */
        $receipt = $this->validatedEvent?->receipt;
        $ready = $receipt !== null && $receipt->file_generation_status === 'ready';

        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'total' => Money::ofMinor($this->total_amount_minor, Currency::from($this->currency))->toArray(),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'submitted_for_validation_at' => $this->submitted_for_validation_at?->toIso8601String(),
            'invoice' => [
                'id' => $invoice->ulid,
                'number' => $invoice->invoice_number,
                'status' => $invoice->status->value,
            ],
            'receipt' => [
                'ready' => $ready,
                'id' => $ready ? $receipt->ulid : null,
                'number' => $ready ? $receipt->receipt_number : null,
            ],
        ];
    }
}
