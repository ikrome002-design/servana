<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Concerns;

use App\Domain\Invoicing\Models\Invoice;

/**
 * Builds the SAFE audit context for an invoice event (Plan §70; Phase 17).
 *
 * Only safe ids and financial facts — invoice/client ULIDs, the invoice number,
 * status, currency, integer minor-unit totals, the preferred-fee snapshot, and the
 * item count. Never full phone/email, blind index, tokens, raw idempotency keys,
 * headers, full bodies, or sequential database ids. Per-action callers merge extra
 * safe keys (prev/new state, sanitised reason, preferred-fee source, before/after
 * totals).
 */
trait BuildsInvoiceAudit
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function invoiceAuditContext(Invoice $invoice, array $extra = []): array
    {
        $base = [
            'invoice_id' => $invoice->ulid,
            'invoice_number' => $invoice->invoice_number,
            'client_id' => $invoice->client?->ulid,
            'status' => $invoice->status->value,
            'currency' => $invoice->currency,
            'subtotal_minor' => $invoice->subtotal_minor,
            'discount_minor' => $invoice->discount_minor,
            'tax_minor' => $invoice->tax_minor,
            'preferred_personnel_fee_snapshot_minor' => $invoice->preferred_personnel_fee_snapshot_minor,
            'total_minor' => $invoice->total_minor,
            'validated_paid_minor' => $invoice->validated_paid_minor,
        ];

        return array_merge($base, $extra);
    }
}
