<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Invoice payload (Plan §13.8, §40; guardrail §6.4; Phase 17). Exposes the invoice
 * ULID, the invoice number (null while draft), status, a MASKED client summary, the
 * immutable money snapshots as { amount, currency, formatted } objects, the
 * validated-paid amount (0 in Phase 17) and remaining balance, the line items, and a
 * state-aware `can` capability map (policy permission AND current legal transition —
 * UX only). Internal bigint ids, full phone/email, the blind index, SQLSTATE,
 * constraint names, and raw idempotency keys are NEVER serialized.
 *
 * @mixin Invoice
 */
final class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = Currency::from($this->currency);

        return [
            'id' => $this->ulid,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status->value,
            'is_draft' => $this->status === InvoiceStatus::Draft,
            'currency' => $this->currency,
            'client' => $this->whenLoaded('client', function (): array {
                /** @var Client $client */
                $client = $this->client;

                return [
                    'id' => $client->ulid,
                    'full_name' => $client->full_name,
                    'phone_masked' => $client->maskedPhone(),
                    'phone_last_four' => $client->phone_last_four,
                ];
            }),
            'subtotal' => Money::ofMinor($this->subtotal_minor, $currency)->toArray(),
            'discount' => Money::ofMinor($this->discount_minor, $currency)->toArray(),
            'tax' => Money::ofMinor($this->tax_minor, $currency)->toArray(),
            'preferred_personnel_fee' => $this->preferred_personnel_fee_snapshot_minor === null
                ? null
                : Money::ofMinor($this->preferred_personnel_fee_snapshot_minor, $currency)->toArray(),
            // Phase 20E — the client-facing platform-fee line: the portion of the percentage platform fee
            // shifted onto THIS merchant-client invoice (already included in `total`). Present only when
            // there is a positive shifted amount (shared / business-centric tiers); customer-centric shifts
            // nothing and fixed-only invoices have no percentage fee, so both render no line. Merchant
            // liability and internal rate configuration are never exposed on this client-facing payload.
            'platform_fee_client_shifted' => ($this->platform_fee_client_shifted_minor === null || $this->platform_fee_client_shifted_minor <= 0)
                ? null
                : Money::ofMinor($this->platform_fee_client_shifted_minor, $currency)->toArray(),
            'total' => Money::ofMinor($this->total_minor, $currency)->toArray(),
            'validated_paid' => Money::ofMinor($this->validated_paid_minor, $currency)->toArray(),
            'balance' => Money::ofMinor($this->balanceMinor(), $currency)->toArray(),
            'percentage_fee_config_snapshot' => $this->percentage_fee_config_snapshot,
            'finalized_at' => $this->finalized_at === null ? null : $this->finalized_at->toIso8601String(),
            'voided_at' => $this->voided_at === null ? null : $this->voided_at->toIso8601String(),
            'void_reason' => $this->void_reason,
            'adjusted_at' => $this->adjusted_at === null ? null : $this->adjusted_at->toIso8601String(),
            'adjustment_reason' => $this->adjustment_reason,
            'created_at' => $this->created_at === null ? null : $this->created_at->toIso8601String(),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'can' => $this->capabilities(),
        ];
    }

    /**
     * State-aware capability map (policy permission AND the current legal transition).
     * UX only — the API is the security boundary.
     *
     * @return array<string, bool>
     */
    private function capabilities(): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->resource;
        $isDraft = $invoice->status === InvoiceStatus::Draft;
        $isPayable = in_array($invoice->status, InvoiceStatus::payableStatuses(), true);
        $isVoidPending = $invoice->status === InvoiceStatus::VoidPending;

        return [
            'update' => $isDraft && Gate::allows('update', $invoice),
            'finalize' => $isDraft && Gate::allows('finalize', $invoice),
            'void' => $isPayable && Gate::allows('void', $invoice),
            'void_execute' => $isVoidPending && Gate::allows('void', $invoice),
            'void_reject' => $isVoidPending && Gate::allows('void', $invoice),
            'adjust' => $isPayable && Gate::allows('adjust', $invoice),
        ];
    }
}
