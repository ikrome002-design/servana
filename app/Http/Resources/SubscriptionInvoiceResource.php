<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\SubscriptionInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Subscription-invoice payload (Plan §49; ADR-014; Phase 20B). Immutable financial snapshot in
 * integer minor units. Exposes the invoice number, period, totals, and status; surfaces the
 * `payment_reference_pending` flag (true until a Wallet reference exists — 20D-W) and the account
 * reference ONLY when present. `has_pdf` reflects a generated PDF (download via the download-link
 * route). Never leaks internal ids, the Wallet payment id, or the file path/signature.
 *
 * @mixin SubscriptionInvoice
 */
final class SubscriptionInvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status->value,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'subtotal_minor' => $this->subtotal_minor,
            'discount_minor' => $this->discount_minor,
            'total_minor' => $this->total_minor,
            'balance_minor' => $this->balance_minor,
            'currency' => $this->currency,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'payment_reference_pending' => ! $this->hasWalletReference(),
            'account_reference' => $this->hasWalletReference() ? $this->account_reference : null,
            'has_pdf' => $this->file_id !== null,
            'pdf_version' => $this->pdf_version,
        ];
    }
}
