<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Invoicing\Concerns\BuildsInvoiceAudit;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoiceStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Finance adjusts an issued/partially-paid invoice (Plan §25.3, §40; Phase 17;
 * issued|partially_paid → adjusted; Gate B). Finance only
 * (`invoice.adjustment.manage`, MFA enforced at the route boundary). The
 * representation is ADDITIVE and non-destructive: the original finalized item +
 * header monetary snapshots and the invoice number are NEVER rewritten and no row is
 * deleted — the original is marked `adjusted` (superseded) with actor/time/reason
 * recorded, and a later correcting invoice (Phase 18B for paid invoices) links back
 * via `adjustment_of_invoice_id`. The `invoice.adjusted` audit event records the
 * before/after snapshot and reason. Period-lock enforced. Failure rolls back with no
 * success audit.
 */
final class AdjustInvoice
{
    use BuildsInvoiceAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly InvoiceStateMachine $machine,
        private readonly FinancialPeriodGuard $periodGuard,
    ) {}

    public function handle(Invoice $invoice, User $actor, string $reason): Invoice
    {
        $this->periodGuard->ensureOpen($invoice->merchant_id, $invoice->branch_id);

        return DB::transaction(function () use ($invoice, $actor, $reason): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $previous = $locked->status;
            $this->machine->ensure($previous, InvoiceStatus::Adjusted);

            // Original snapshots + number are intentionally left untouched (additive).
            $locked->status = InvoiceStatus::Adjusted;
            $locked->adjusted_at = now();
            $locked->adjusted_by = $actor->id;
            $locked->adjustment_reason = $reason;
            $locked->save();

            $locked->load('client');

            $this->audit->record(
                AuditEvent::InvoiceAdjusted,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->invoiceAuditContext($locked, [
                    'previous_state' => $previous->value,
                    'new_state' => InvoiceStatus::Adjusted->value,
                    'reason' => $reason,
                ]),
            );

            return $locked;
        });
    }
}
