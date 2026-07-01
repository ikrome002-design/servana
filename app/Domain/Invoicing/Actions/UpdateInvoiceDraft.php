<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Clients\Models\Client;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Invoicing\Concerns\BuildsInvoiceAudit;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoiceStateException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Services\InvoiceDraftComposer;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Replace the item set of a DRAFT invoice (Plan §40; Phase 17; draft → draft). Front
 * Office only. The invoice must be `draft` (an issued invoice rejects a draft edit →
 * `422 invalid_state_transition`). The new source set is re-validated and re-derived
 * from the locked completed sessions/services via {@see InvoiceDraftComposer} — the
 * browser never supplies authoritative price/personnel/currency/totals. Existing
 * items are cleared and rewritten atomically (removing items is allowed only before
 * finalization); no invoice number and no finalized timestamp are written. One
 * coherent `invoice.updated_draft` event; any failure rolls back items, totals, and
 * audit. Period-lock enforced.
 */
final class UpdateInvoiceDraft
{
    use BuildsInvoiceAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly InvoiceDraftComposer $composer,
        private readonly FinancialPeriodGuard $periodGuard,
    ) {}

    /**
     * @param  list<ServiceSession>  $sessions
     */
    public function handle(Invoice $invoice, Client $client, array $sessions, User $actor): Invoice
    {
        $this->periodGuard->ensureOpen($invoice->merchant_id, $invoice->branch_id);

        return DB::transaction(function () use ($invoice, $client, $sessions, $actor): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            // Only a draft may be edited as a draft; anything else is read-only.
            if ($locked->status !== InvoiceStatus::Draft) {
                throw InvoiceStateException::invalidTransition($locked->status, InvoiceStatus::Draft);
            }

            // Clear existing items first so a session currently on THIS draft is free to
            // be re-selected (the UNIQUE(service_session_id) duplicate-invoicing guard
            // then only sees other invoices).
            InvoiceItem::query()->where('invoice_id', $locked->id)->delete();

            $this->composer->compose($locked, $client, $sessions);

            $locked->load(['client', 'items']);

            $this->audit->record(
                AuditEvent::InvoiceUpdatedDraft,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->invoiceAuditContext($locked, [
                    'item_count' => $locked->items->count(),
                    'new_state' => InvoiceStatus::Draft->value,
                ]),
            );

            return $locked;
        });
    }
}
