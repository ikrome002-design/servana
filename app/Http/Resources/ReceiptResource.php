<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Receipts\Models\Receipt;
use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Receipt payload (Plan §43; guardrail §6.4; Phase 18B). Exposes the receipt ULID +
 * number, integer minor-unit amount + currency, SAFE component snapshots (method +
 * amount only), the invoice public reference, the reissue linkage (ULID only), the
 * PDF generation status, and a capability map. It NEVER exposes a sequential id, a
 * full/normalized reference, a storage path, a signed URL, or an internal file id.
 *
 * @mixin Receipt
 */
final class ReceiptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = Currency::from($this->currency);

        return [
            'id' => $this->ulid,
            'receipt_number' => $this->receipt_number,
            'amount' => Money::ofMinor($this->amount_minor, $currency)->toArray(),
            'currency' => $this->currency,
            'components' => array_map(static fn (array $c): array => [
                'method' => (string) $c['method'],
                'amount' => Money::ofMinor((int) $c['amount_minor'], $currency)->toArray(),
            ], $this->components),
            'is_reissue' => $this->isReissue(),
            // reissue_of_receipt_id is a nullable FK, so a loaded relation can still be null.
            'reissue_of' => $this->whenLoaded('reissueOf', fn (): ?string => $this->reissueOf === null ? null : $this->reissueOf->ulid),
            'reason' => $this->reason,
            'downloadable' => $this->file_generation_status === 'ready',
            'file_generation_status' => $this->file_generation_status,
            'created_at' => $this->created_at === null ? null : $this->created_at->toIso8601String(),
            'invoice' => $this->whenLoaded('invoice', function (): array {
                /** @var Invoice $invoice */
                $invoice = $this->invoice;

                return ['id' => $invoice->ulid, 'invoice_number' => $invoice->invoice_number];
            }),
        ];
    }
}
