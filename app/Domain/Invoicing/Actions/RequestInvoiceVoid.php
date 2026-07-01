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
 * Finance requests a void on an issued/partially-paid invoice (Plan §25.3, §40;
 * Phase 17; issued|partially_paid → void_pending). Finance only
 * (`invoice.void.request_or_execute_as_policy`, MFA + step-up enforced at the route
 * boundary). A sanitised reason is mandatory. The prior payable state is preserved
 * in `previous_status` so a rejection restores it exactly. Monetary snapshots and the
 * invoice number are NOT touched; no row is deleted. Period-lock enforced. Failure
 * rolls back with no success audit.
 */
final class RequestInvoiceVoid
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
            $this->machine->ensure($previous, InvoiceStatus::VoidPending);

            $locked->previous_status = $previous;
            $locked->status = InvoiceStatus::VoidPending;
            $locked->void_reason = $reason;
            $locked->save();

            $locked->load('client');

            $this->audit->record(
                AuditEvent::InvoiceVoidRequested,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->invoiceAuditContext($locked, [
                    'previous_state' => $previous->value,
                    'new_state' => InvoiceStatus::VoidPending->value,
                    'reason' => $reason,
                ]),
            );

            return $locked;
        });
    }
}
