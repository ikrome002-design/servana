<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Services\CommissionHandoffWriter;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoiceStateMachine;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Services\PaymentRecordingGroupStateMachine;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Exceptions\RefundException;
use App\Domain\Refunds\Models\Refund;
use App\Domain\Refunds\Services\RefundStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Finalize an approved refund (Plan §44; Gate E; Phase 18B). Additive and
 * non-destructive: original payment/receipt/validation rows are preserved. One atomic
 * transaction reduces `invoices.validated_paid_minor` by the allocated amount, derives
 * the invoice payment state deterministically, marks the component `adjusted` (partial)
 * or `reversed` (full), reverses the whole group only when every component is reversed,
 * writes a durable per-component 20G reversal handoff (no invented rate), and audits.
 * Requires a fresh MFA step-up (route) and the maker/checker separation from approval.
 */
final class FinalizeRefund
{
    public function __construct(
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly InvoiceStateMachine $invoiceMachine,
        private readonly PaymentRecordingGroupStateMachine $groupMachine,
        private readonly RefundStateMachine $machine,
        private readonly CommissionHandoffWriter $commission,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Refund $refund, User $finalizer): Refund
    {
        $this->periodGuard->ensureOpen($refund->merchant_id, $refund->branch_id);

        return DB::transaction(function () use ($refund, $finalizer): Refund {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($locked->requested_by === $finalizer->id) {
                throw RefundException::makerIsChecker();
            }
            $this->machine->ensure($locked->status, RefundStatus::Finalized);

            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($locked->invoice_id)->lockForUpdate()->firstOrFail();
            /** @var PaymentRecord $component */
            $component = PaymentRecord::query()->whereKey($locked->payment_record_id)->lockForUpdate()->firstOrFail();

            $now = CarbonImmutable::now();

            // 1) Finalize the refund.
            $locked->forceFill([
                'status' => RefundStatus::Finalized->value,
                'finalized_by' => $finalizer->id,
                'finalized_at' => $now,
            ])->save();

            // 2) Reduce the invoice recognised balance (must stay within 0..total).
            $newValidatedPaid = $invoice->validated_paid_minor - $locked->amount_minor;
            if ($newValidatedPaid < 0 || $newValidatedPaid > $invoice->total_minor) {
                throw RefundException::balanceOutOfRange();
            }
            $invoice->validated_paid_minor = $newValidatedPaid;

            // 3) Component: full reversal → reversed, partial → adjusted.
            $finalizedForComponent = (int) Refund::query()
                ->where('payment_record_id', $component->id)
                ->where('status', RefundStatus::Finalized->value)
                ->sum('amount_minor');
            $componentValidated = (int) ($component->validated_amount_minor ?? 0);
            $componentTarget = $finalizedForComponent >= $componentValidated
                ? PaymentRecordStatus::Reversed
                : PaymentRecordStatus::Adjusted;
            $component->forceFill(['status' => $componentTarget->value])->save();

            // 4) Durable per-component 20G reversal handoff (no invented rate).
            $this->commission->recordReversal($locked, $component, $locked->amount_minor, $now);

            // 5) Group reversed ONLY when every component is reversed; else stays validated.
            $this->maybeReverseGroup($component->payment_recording_group_id);

            // 6) Derive the invoice payment state when no in-flight refund remains.
            $this->deriveInvoiceState($invoice);

            $this->audit->record(AuditEvent::RefundFinalized, $finalizer, $locked->merchant_id, $locked->branch_id, $locked, [
                'refund_id' => $locked->ulid,
                'invoice_id' => $invoice->ulid,
                'payment_record_id' => $component->ulid,
                'amount_minor' => $locked->amount_minor,
                'currency' => $locked->currency,
                'component_state' => $componentTarget->value,
                'invoice_validated_paid_minor' => $invoice->validated_paid_minor,
                'invoice_state' => $invoice->status->value,
            ]);

            return $locked->setRelation('invoice', $invoice);
        });
    }

    private function maybeReverseGroup(int $groupId): void
    {
        /** @var PaymentRecordingGroup $group */
        $group = PaymentRecordingGroup::query()->whereKey($groupId)->lockForUpdate()->firstOrFail();

        if ($group->status !== PaymentRecordingGroupStatus::Validated) {
            return;
        }

        $unreversed = PaymentRecord::query()
            ->where('payment_recording_group_id', $group->id)
            ->where('status', '!=', PaymentRecordStatus::Reversed->value)
            ->exists();

        if (! $unreversed) {
            $this->groupMachine->ensure($group->status, PaymentRecordingGroupStatus::Reversed);
            $group->forceFill(['status' => PaymentRecordingGroupStatus::Reversed->value])->save();
        }
    }

    private function deriveInvoiceState(Invoice $invoice): void
    {
        $inFlight = Refund::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', [RefundStatus::Requested->value, RefundStatus::Approved->value])
            ->exists();

        if ($inFlight) {
            $invoice->save(); // keep refund_pending; the balance change is already applied

            return;
        }

        $target = match (true) {
            $invoice->validated_paid_minor === 0 => InvoiceStatus::Issued,
            $invoice->validated_paid_minor === $invoice->total_minor => InvoiceStatus::Paid,
            default => InvoiceStatus::PartiallyPaid,
        };

        if ($invoice->status !== $target) {
            $this->invoiceMachine->ensure($invoice->status, $target);
            $invoice->status = $target;
        }
        $invoice->save();
    }
}
