<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoiceStateMachine;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Exceptions\RefundException;
use App\Domain\Refunds\Models\Refund;
use App\Domain\Refunds\Services\RefundableBalanceCalculator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Request an EXTERNAL refund against a validated payment component (Plan §44; Gate D/E;
 * Phase 18B). Servana never moves funds — this records the intent. One atomic
 * transaction: period gate → lock the component → require it validated → amount within
 * remaining refundable → method-aware external reference → create the refund
 * (`requested`) → move the invoice to `refund_pending` (preserving the prior payable
 * state for rejection recovery) → safe masked audit. The requester may not later approve
 * (maker/checker, enforced in {@see ApproveRefund}).
 */
final class RequestRefund
{
    public function __construct(
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly InvoiceStateMachine $invoiceMachine,
        private readonly RefundableBalanceCalculator $refundable,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PaymentRecord $component, User $requester, int $amountMinor, PaymentMethod $method, string $reason, ?string $externalReference): Refund
    {
        $this->periodGuard->ensureOpen($component->merchant_id, $component->branch_id);

        return DB::transaction(function () use ($component, $requester, $amountMinor, $method, $reason, $externalReference): Refund {
            /** @var PaymentRecord $locked */
            $locked = PaymentRecord::query()->whereKey($component->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentRecordStatus::Validated) {
                throw RefundException::componentNotValidated();
            }
            if ($amountMinor <= 0 || $amountMinor > $this->refundable->remainingMinor($locked)) {
                throw RefundException::exceedsRefundable();
            }
            if ($method->requiresReference() && ($externalReference === null || trim($externalReference) === '')) {
                throw RefundException::referenceRequired();
            }

            $refund = Refund::create([
                'merchant_id' => $locked->merchant_id,
                'branch_id' => $locked->branch_id,
                'invoice_id' => $locked->invoice_id,
                'payment_record_id' => $locked->id,
                'amount_minor' => $amountMinor,
                'currency' => $locked->currency,
                'method' => $method,
                'external_reference_encrypted' => $externalReference !== null && trim($externalReference) !== '' ? $externalReference : null,
                'reason' => $reason,
                'status' => RefundStatus::Requested,
                'requested_by' => $requester->id,
            ]);

            // Move the invoice into refund_pending. The prior paid state is not stored
            // (the invoices.previous_status CHECK is void-only); reject/finalize derive
            // the resulting state from validated_paid_minor, which reject leaves unchanged.
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($locked->invoice_id)->lockForUpdate()->firstOrFail();
            if (in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::PartiallyPaid], true)) {
                $this->invoiceMachine->ensure($invoice->status, InvoiceStatus::RefundPending);
                $invoice->status = InvoiceStatus::RefundPending;
                $invoice->save();
            }

            $this->audit->record(AuditEvent::RefundRequested, $requester, $refund->merchant_id, $refund->branch_id, $refund, [
                'refund_id' => $refund->ulid,
                'invoice_id' => $invoice->ulid,
                'payment_record_id' => $locked->ulid,
                'amount_minor' => $refund->amount_minor,
                'currency' => $refund->currency,
                'method' => $refund->method->value,
                'reference_masked' => $refund->maskedReference(),
                'invoice_state' => $invoice->status->value,
            ]);

            return $refund->setRelation('invoice', $invoice)->setRelation('paymentRecord', $locked);
        });
    }
}
