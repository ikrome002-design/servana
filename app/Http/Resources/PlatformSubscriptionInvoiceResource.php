<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A subscription invoice as the platform sees it (COR-UI08-001 §10; Phase UI-08).
 *
 * THE ISSUED SNAPSHOT EXACTLY AS STORED. Every money field is read straight from the row and
 * rendered through {@see Money}; nothing here recomputes a subtotal, re-applies a discount or
 * re-derives a balance. An issued invoice is an immutable financial record (ADR-014), and a
 * governance screen that quietly recalculated one would be reporting a different invoice from the
 * one the merchant received.
 *
 * Wallet columns are the nullable projections Servana is permitted to hold. They ship at their
 * 20B defaults (null / unregistered) and are populated only by 20D-W behind Gate W; they are
 * surfaced as-is and never inferred.
 *
 * @mixin SubscriptionInvoice
 */
final class PlatformSubscriptionInvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = Currency::from($this->currency);

        return [
            'id' => $this->ulid,
            'invoice_number' => $this->invoice_number,
            'merchant' => [
                'id' => $this->merchant?->ulid,
                'name' => $this->merchant?->name,
            ],
            'plan' => [
                'id' => $this->plan?->ulid,
                'key' => $this->plan?->key,
            ],
            'status' => $this->status->value,
            'period_start' => $this->period_start->toIso8601String(),
            'period_end' => $this->period_end->toIso8601String(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'subtotal' => Money::ofMinor($this->subtotal_minor, $currency)->toArray(),
            'discount' => Money::ofMinor($this->discount_minor, $currency)->toArray(),
            'total' => Money::ofMinor($this->total_minor, $currency)->toArray(),
            'balance' => Money::ofMinor($this->balance_minor, $currency)->toArray(),
            'currency' => $this->currency,
            'snapshot_note' => 'Issued invoices are immutable financial records; these figures are the stored snapshot, never a recalculation.',
            'wallet' => [
                'registration_status' => $this->wallet_registration_status->value,
                'registered_at' => $this->wallet_registered_at?->toIso8601String(),
                // Money movement is Wallet truth (ADR-012). Servana holds this projection and
                // never a provider reference, credential or callback payload.
                'authority' => 'wallet_by_citrus',
            ],
        ];
    }
}
