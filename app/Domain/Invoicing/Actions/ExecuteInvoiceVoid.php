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
 * Finance executes a pending void (Plan §25.3, §40; Phase 17; void_pending →
 * voided). Finance only (`invoice.void.request_or_execute_as_policy`, MFA + step-up
 * at the route boundary). The void is ADDITIVE and non-destructive: the original
 * items + monetary snapshots remain unchanged, the invoice number is retained (never
 * reused), and no row is deleted — only `voided_at`/`voided_by` are stamped and the
 * preserved `previous_status` is cleared. Period-lock enforced. Failure rolls back
 * with no success audit.
 */
final class ExecuteInvoiceVoid
{
    use BuildsInvoiceAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly InvoiceStateMachine $machine,
        private readonly FinancialPeriodGuard $periodGuard,
    ) {}

    public function handle(Invoice $invoice, User $actor): Invoice
    {
        $this->periodGuard->ensureOpen($invoice->merchant_id, $invoice->branch_id);

        return DB::transaction(function () use ($invoice, $actor): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, InvoiceStatus::Voided);

            $locked->status = InvoiceStatus::Voided;
            $locked->voided_at = now();
            $locked->voided_by = $actor->id;
            // The void_reason captured at request is retained; clear the preserved
            // payable state (a non-void_pending row must have a null previous_status).
            $locked->previous_status = null;
            $locked->save();

            $locked->load('client');

            $this->audit->record(
                AuditEvent::InvoiceVoided,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->invoiceAuditContext($locked, [
                    'previous_state' => InvoiceStatus::VoidPending->value,
                    'new_state' => InvoiceStatus::Voided->value,
                    'reason' => $locked->void_reason,
                ]),
            );

            return $locked;
        });
    }
}
