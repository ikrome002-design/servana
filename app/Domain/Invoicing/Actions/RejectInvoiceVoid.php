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
 * Finance rejects a pending void (Plan §25.3, §40; Phase 17; void_pending →
 * issued|partially_paid). Finance only (`invoice.void.request_or_execute_as_policy`).
 * Restores the exact prior payable state captured in `previous_status` and clears the
 * void metadata. Monetary snapshots and the invoice number are untouched. Period-lock
 * enforced. Failure rolls back with no success audit.
 */
final class RejectInvoiceVoid
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

            // Restore the preserved payable state (issued | partially_paid).
            $target = $locked->previous_status ?? InvoiceStatus::Issued;
            $this->machine->ensure($locked->status, $target);

            $locked->status = $target;
            $locked->previous_status = null;
            $locked->void_reason = null;
            $locked->save();

            $locked->load('client');

            $this->audit->record(
                AuditEvent::InvoiceVoidRejected,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->invoiceAuditContext($locked, [
                    'previous_state' => InvoiceStatus::VoidPending->value,
                    'new_state' => $target->value,
                ]),
            );

            return $locked;
        });
    }
}
