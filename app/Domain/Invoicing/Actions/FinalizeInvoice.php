<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Services\RecordPlatformFeeAtFinalization;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Domain\Invoicing\Concerns\BuildsInvoiceAudit;
use App\Domain\Invoicing\Contracts\PreferredPersonnelFeeResolver;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Exceptions\InvoiceSourceException;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Services\InvoiceNumberAllocator;
use App\Domain\Invoicing\Services\InvoiceStateMachine;
use App\Domain\Invoicing\Services\InvoiceTotalsCalculator;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Enums\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finalize a draft invoice (Plan §40, §25.3; Phase 17; draft → issued). Front
 * Office only; classified `financial_mutation` (route-level idempotency via
 * EnsureIdempotentRequest). In ONE transaction it locks the invoice, locks each
 * source service session + service, re-confirms every source is still completed and
 * not otherwise invoiced, re-derives prices and the preferred-personnel fee from the
 * locked authoritative data (Gate D), resolves the null percentage-fee config seam
 * (Gate E), recomputes totals in integer minor units, allocates exactly one gap-free
 * per-merchant number under the sequence row lock, writes the immutable item + header
 * snapshots, transitions draft → issued, sets finalized_at, and emits the
 * finalization audit event. Any failure rolls back: no number consumed, no issued
 * invoice, no snapshot change, no success audit.
 */
final class FinalizeInvoice
{
    use BuildsInvoiceAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly InvoiceStateMachine $machine,
        private readonly InvoiceTotalsCalculator $totals,
        private readonly PreferredPersonnelFeeResolver $preferredFee,
        private readonly InvoiceNumberAllocator $allocator,
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly RecordPlatformFeeAtFinalization $platformFee,
    ) {}

    public function handle(Invoice $invoice, User $actor): Invoice
    {
        $this->periodGuard->ensureOpen($invoice->merchant_id, $invoice->branch_id);

        return DB::transaction(function () use ($invoice, $actor): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            // Only a draft can be finalized; any other state → 422 invalid_state_transition.
            $this->machine->ensure($locked->status, InvoiceStatus::Issued);

            /** @var Collection<int, InvoiceItem> $items */
            $items = InvoiceItem::query()->where('invoice_id', $locked->id)->get();
            if ($items->isEmpty()) {
                throw InvoiceSourceException::noSources();
            }

            $currency = Currency::from($locked->currency);
            $lineTotals = [];

            foreach ($items as $item) {
                /** @var ServiceSession $session */
                $session = ServiceSession::query()->whereKey($item->service_session_id)->lockForUpdate()->firstOrFail();
                if ($session->status !== ServiceSessionStatus::Completed) {
                    throw InvoiceSourceException::invalidSessionState();
                }

                /** @var Service $service */
                $service = Service::query()->whereKey($item->service_id)->lockForUpdate()->firstOrFail();

                // Authoritative snapshot — derived under lock, never from the browser.
                $unitPrice = (int) $service->price_minor;
                $fee = $this->preferredFee->resolve($session, $service);

                $item->description = $service->name;
                $item->quantity = 1;
                $item->unit_price_minor = $unitPrice;
                $item->line_total_minor = $unitPrice;
                $item->preferred_personnel_fee_minor = $fee->amountMinor;
                $item->currency = $currency->value;
                $item->save();

                $lineTotals[] = ['line_total_minor' => $unitPrice, 'preferred_personnel_fee_minor' => $fee->amountMinor];
            }

            $computed = $this->totals->compute($lineTotals, $locked->tax_minor, $locked->discount_minor, $currency);

            // Phase 20E — resolve/compute/snapshot the percentage platform fee BEFORE the number is
            // allocated, so a fail-closed configuration/tier error consumes no invoice number. A true
            // no-op in fixed-only mode (inactive result → all snapshots null, zero client-shifted delta).
            $now = CarbonImmutable::now();
            $feeResult = $this->platformFee->apply($locked, $items, $computed, $now);

            /** @var MerchantBranch $branch */
            $branch = MerchantBranch::query()->whereKey($locked->branch_id)->firstOrFail();
            $number = $this->allocator->allocate($locked->merchant_id, $branch->code);

            $locked->subtotal_minor = $computed->subtotalMinor;
            $locked->preferred_personnel_fee_snapshot_minor = $computed->preferredFeeTotalMinor;
            // The tier's client-shifted amount (0 unless shared/business_centric) is added to the total.
            $locked->total_minor = $computed->totalMinor + $feeResult->clientShiftedDeltaMinor();
            // Structured Phase 20E snapshot (null in fixed-only mode). The legacy JSON seam stays null.
            $locked->forceFill($feeResult->headerSnapshot());
            $locked->percentage_fee_config_snapshot = null;
            $locked->invoice_number = $number;
            $locked->status = InvoiceStatus::Issued;
            $locked->finalized_at = now();
            $locked->save();

            $locked->load(['client', 'items']);

            $this->audit->record(
                AuditEvent::InvoiceFinalized,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->invoiceAuditContext($locked, [
                    'previous_state' => InvoiceStatus::Draft->value,
                    'new_state' => InvoiceStatus::Issued->value,
                    'item_count' => $locked->items->count(),
                    ...$feeResult->auditContext(),
                ]),
            );

            return $locked;
        });
    }
}
