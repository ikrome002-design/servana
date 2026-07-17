<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Percentage platform-fee ledger-entry payload — the merchant/Finance masked read (Plan §51; Phase 20E).
 * Exposes the entry ULID, public merchant/branch/invoice references, billing mode, tier, fee basis +
 * amount, rate, the gross/shifted/absorbed/liability integer amounts, currency, entry type/status,
 * billable_at, and the subscription-invoice rollup public reference. NEVER exposes internal ids, raw
 * payment references, private client contact, blind indexes, encrypted values, or idempotency keys.
 *
 * @mixin PlatformFeeLedgerEntry
 */
final class PlatformFeeLedgerEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            // branch_id, source_invoice_item_id and subscription_invoice_item_id are nullable
            // FKs, so each null branch is spelled out: the OpenAPI generator infers nullability
            // from an explicit null ternary but not through the nullsafe operator. merchant_id
            // and source_invoice_id are non-nullable FKs and stay as-is.
            'merchant_id' => $this->merchant?->ulid,
            'branch_id' => $this->branch === null ? null : $this->branch->ulid,
            'source_invoice_id' => $this->sourceInvoice?->ulid,
            'source_invoice_item_id' => $this->sourceInvoiceItem === null ? null : $this->sourceInvoiceItem->ulid,
            'entry_type' => $this->entry_type->value,
            'status' => $this->status->value,
            'billing_mode' => $this->billing_mode_snapshot->value,
            'service_fee_tier' => $this->service_fee_tier_snapshot->value,
            'fee_basis_type' => $this->fee_basis_type->value,
            'fee_basis_amount_minor' => $this->fee_basis_amount_minor,
            'percentage_rate_basis_points' => $this->percentage_rate_snapshot,
            'shared_split_basis_points' => $this->shared_split_snapshot,
            'gross_platform_fee_minor' => $this->gross_platform_fee_minor,
            'client_shifted_amount_minor' => $this->client_shifted_amount_minor,
            'merchant_absorbed_amount_minor' => $this->merchant_absorbed_amount_minor,
            'merchant_liability_minor' => $this->merchant_liability_minor,
            'currency' => $this->currency,
            'subscription_invoice_item_id' => $this->subscriptionInvoiceItem === null ? null : $this->subscriptionInvoiceItem->ulid,
            'billable_at' => $this->billable_at === null ? null : $this->billable_at->toIso8601String(),
        ];
    }
}
