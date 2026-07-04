<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoiceStateMachine;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Models\Refund;
use App\Domain\Refunds\Services\RefundStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reject a requested/approved refund (Plan §44; Phase 18B). Non-destructive: the
 * recognised balance (`validated_paid_minor`) is unchanged; if no other in-flight refund
 * remains for the invoice, the invoice's prior derived paid state is restored from
 * `previous_status`. Period gate enforced. One atomic transaction.
 */
final class RejectRefund
{
    public function __construct(
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly InvoiceStateMachine $invoiceMachine,
        private readonly RefundStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Refund $refund, User $actor): Refund
    {
        $this->periodGuard->ensureOpen($refund->merchant_id, $refund->branch_id);

        return DB::transaction(function () use ($refund, $actor): Refund {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, RefundStatus::Rejected);

            $locked->forceFill([
                'status' => RefundStatus::Rejected->value,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
            ])->save();

            $this->restoreInvoiceIfSettled($locked);

            $this->audit->record(AuditEvent::RefundRejected, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
                'refund_id' => $locked->ulid,
                'amount_minor' => $locked->amount_minor,
                'currency' => $locked->currency,
            ]);

            return $locked;
        });
    }

    /** Restore the invoice's prior paid state when no in-flight refund remains. */
    private function restoreInvoiceIfSettled(Refund $refund): void
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->whereKey($refund->invoice_id)->lockForUpdate()->firstOrFail();

        if ($invoice->status !== InvoiceStatus::RefundPending) {
            return;
        }

        $inFlight = Refund::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', [RefundStatus::Requested->value, RefundStatus::Approved->value])
            ->exists();

        if ($inFlight) {
            return;
        }

        // Reject leaves validated_paid_minor unchanged, so derive the restored state
        // from it (the invoices.previous_status CHECK is void-only).
        $target = match (true) {
            $invoice->validated_paid_minor === 0 => InvoiceStatus::Issued,
            $invoice->validated_paid_minor === $invoice->total_minor => InvoiceStatus::Paid,
            default => InvoiceStatus::PartiallyPaid,
        };
        $this->invoiceMachine->ensure(InvoiceStatus::RefundPending, $target);
        $invoice->status = $target;
        $invoice->save();
    }
}
