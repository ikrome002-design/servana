<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Actions\IssueSubscriptionInvoice;
use App\Domain\Billing\Enums\PlatformFeeEntryType;
use App\Domain\Billing\Enums\PlatformFeeLedgerStatus;
use App\Domain\Billing\Enums\SubscriptionInvoiceItemType;
use App\Domain\Billing\Models\PlatformFeeAdjustment;
use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionInvoiceItem;
use App\Domain\Billing\ValueObjects\PlatformFeeCorrectionSelection;
use App\Domain\Billing\ValueObjects\PlatformFeeRollupSelection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates earned/pending platform-fee liabilities into a single `platform_fee_rollup` line on the
 * period's subscription invoice (Plan §51, §49; Phase 20E, Increment 5A). It is a collaborator of the
 * Phase 20B {@see IssueSubscriptionInvoice} issuance transaction — NOT a
 * second subscription-invoice aggregate.
 *
 * Because `subscription_invoices` requires a plan/price and its financial snapshot is immutable once
 * issued, the rollup is folded into the invoice AT issuance in two phases inside one transaction:
 *   1. {@see collectEligible()} — under the subscription row lock, `FOR UPDATE`-locks the eligible
 *      earned/pending entries for one merchant + currency + Africa/Nairobi period, in the deterministic
 *      `billable_at ASC, ulid ASC` order, and returns their integer total so the caller can include it
 *      in the (immutable) subtotal.
 *   2. {@see writeRollup()} — after the invoice row exists, creates the single `platform_fee_rollup`
 *      item, links every selected entry, and transitions `pending → aggregated → invoiced`.
 *
 * Eligibility (all required): `entry_type='earned'`, `status='pending'`, `billable_at` inside the
 * period, matching merchant + currency, not already linked to a subscription-invoice item. Fixed-only
 * records (no earned rows), reversal/adjustment rows, other tenants, and other currencies are excluded.
 * The DB cycle guard (partial-unique rollup index) + the subscription row lock make a duplicate rollup
 * for the same cycle impossible. No Wallet/provider/outbox runtime is touched.
 *
 * It ALSO closes the future-cycle correction path (Phase 20E backend closure): pending `reversal`/
 * `adjustment` entries whose ORIGINAL earned fee was already invoiced are swept — via
 * {@see collectApplicableCorrections()} + {@see writeCorrectionLine()} — into a single signed
 * `adjustment` line on the next invoice, capped so the invoice total can never go negative (residual
 * corrections carry forward). This keeps the append-only ledger and the subscription-billing projection
 * consistent; the original earned fact and the `platform_fee_rollup` line are never mutated.
 */
final class AggregatePlatformFeesIntoSubscriptionInvoice
{
    public function __construct(
        private readonly PlatformFeeLedgerEntryStateMachine $ledgerMachine,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Phase 1 — select + lock the eligible earned/pending entries for the target period. MUST run
     * inside the issuance transaction (the rows stay locked until commit).
     */
    public function collectEligible(
        int $merchantId,
        string $currency,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): PlatformFeeRollupSelection {
        $this->assertInTransaction();

        /** @var Collection<int, PlatformFeeLedgerEntry> $entries */
        $entries = PlatformFeeLedgerEntry::query()
            ->where('merchant_id', $merchantId)
            ->where('entry_type', PlatformFeeEntryType::Earned->value)
            ->where('status', PlatformFeeLedgerStatus::Pending->value)
            ->whereNull('subscription_invoice_item_id')
            ->whereNotNull('billable_at')
            ->where('currency', strtoupper($currency))
            // Africa/Nairobi calendar-day window: inclusive start, exclusive end (matches the Phase 20B
            // period definition). billable_at is a timestamptz; convert to the Nairobi wall-clock date.
            ->whereRaw("(billable_at AT TIME ZONE 'Africa/Nairobi')::date >= ?", [$periodStart->toDateString()])
            ->whereRaw("(billable_at AT TIME ZONE 'Africa/Nairobi')::date < ?", [$periodEnd->toDateString()])
            ->orderBy('billable_at')
            ->orderBy('ulid')
            ->lockForUpdate()
            ->get();

        $total = (int) $entries->sum('merchant_liability_minor');

        return new PlatformFeeRollupSelection($total, $entries);
    }

    /**
     * Phase 2 — write the single rollup line, link every selected entry, and transition it through
     * `pending → aggregated → invoiced`. Emits `platform_fee.aggregated` + `platform_fee.invoiced`.
     * Returns null (and emits nothing) when the selection is empty, so a fixed-only / activity-free
     * cycle leaves the invoice exactly as Phase 20B produced it.
     */
    public function writeRollup(
        SubscriptionInvoice $invoice,
        PlatformFeeRollupSelection $selection,
        ?User $actor,
    ): ?SubscriptionInvoiceItem {
        $this->assertInTransaction();

        if ($selection->isEmpty()) {
            return null;
        }

        $item = new SubscriptionInvoiceItem;
        $item->merchant_id = $invoice->merchant_id;
        $item->subscription_invoice_id = $invoice->id;
        $item->description = 'Platform service fees';
        $item->amount_minor = $selection->totalMinor;
        $item->type = SubscriptionInvoiceItemType::PlatformFeeRollup;
        $item->save();

        $entryUlids = [];
        foreach ($selection->entries as $entry) {
            // pending → aggregated (link the rollup item), then aggregated → invoiced (the parent
            // subscription invoice is issued in this same transaction). Only status + the aggregation
            // link change; the append-only trigger permits exactly these two columns.
            $this->ledgerMachine->ensure($entry->status, PlatformFeeLedgerStatus::Aggregated);
            $entry->forceFill([
                'subscription_invoice_item_id' => $item->id,
                'status' => PlatformFeeLedgerStatus::Aggregated->value,
            ])->save();

            $this->ledgerMachine->ensure(PlatformFeeLedgerStatus::Aggregated, PlatformFeeLedgerStatus::Invoiced);
            $entry->forceFill(['status' => PlatformFeeLedgerStatus::Invoiced->value])->save();

            $entryUlids[] = $entry->ulid;
        }

        $context = [
            'subscription_invoice_id' => $invoice->ulid,
            'subscription_invoice_item_id' => $item->ulid,
            'rollup_total_minor' => $selection->totalMinor,
            'entry_count' => $selection->count(),
            'currency' => $invoice->currency,
            'period_start' => $invoice->period_start->toDateString(),
            'period_end' => $invoice->period_end->toDateString(),
            'ledger_entry_ids' => $entryUlids,
        ];

        $this->audit->record(AuditEvent::PlatformFeeAggregated, $actor, $invoice->merchant_id, null, $item, $context);
        $this->audit->record(AuditEvent::PlatformFeeInvoiced, $actor, $invoice->merchant_id, null, $item, $context);

        return $item;
    }

    /**
     * Phase 20E future-cycle closure — select + FOR UPDATE-lock the eligible pending CORRECTION entries
     * (`reversal`/`adjustment`) for this merchant + currency, dated before the cycle end, whose ORIGINAL
     * earned entry was already invoiced (so we only correct fees that were actually billed — a correction
     * of a still-pending original is a no-op because that original was already dropped from the rollup).
     * Un-invoiced corrections from earlier periods carry forward (no lower date bound — the pending +
     * unlinked predicate is the "not yet processed" gate).
     *
     * Each entry's canonical SIGNED value is its paired `platform_fee_adjustments.amount_minor` (never
     * recomputed), located via the intentional idempotency linkage
     * `ledger.idempotency_key = 'ledger:' || adjustment.idempotency_key`. Entries are consumed greedily in
     * the deterministic `billable_at ASC, ulid ASC` order; a negative correction is consumed only while the
     * running invoice stays non-negative (`base + Σconsumed >= discount`, i.e. `Σconsumed >= -$headroomMinor`
     * where `$headroomMinor = base − discount` = the invoice total before corrections). A correction that
     * would breach the floor is left `pending` (whole-entry carry-forward — an immutable row is never split).
     * MUST run inside the issuance transaction (the rows stay locked until commit).
     */
    public function collectApplicableCorrections(
        int $merchantId,
        string $currency,
        CarbonImmutable $periodEnd,
        int $headroomMinor,
    ): PlatformFeeCorrectionSelection {
        $this->assertInTransaction();

        /** @var Collection<int, PlatformFeeLedgerEntry> $entries */
        $entries = PlatformFeeLedgerEntry::query()
            ->where('merchant_id', $merchantId)
            ->whereIn('entry_type', [PlatformFeeEntryType::Reversal->value, PlatformFeeEntryType::Adjustment->value])
            ->where('status', PlatformFeeLedgerStatus::Pending->value)
            ->whereNull('subscription_invoice_item_id')
            ->whereNotNull('billable_at')
            ->where('currency', strtoupper($currency))
            ->where('idempotency_key', 'like', 'ledger:%')
            // Sweep-forward: everything dated before this cycle's exclusive Nairobi end, no lower bound.
            ->whereRaw("(billable_at AT TIME ZONE 'Africa/Nairobi')::date < ?", [$periodEnd->toDateString()])
            // Only correct a fee that was actually billed: the original earned entry must have reached a
            // subscription-invoice item. A correction of a never-invoiced original is skipped (that
            // original was already dropped from the rollup by its reversed/adjusted marker).
            ->whereExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('platform_fee_ledger_entries as original')
                    ->whereColumn('original.id', 'platform_fee_ledger_entries.reversed_entry_id')
                    ->whereNotNull('original.subscription_invoice_item_id');
            })
            ->orderBy('billable_at')
            ->orderBy('ulid')
            ->lockForUpdate()
            ->get();

        // Canonical signed source: the paired adjustment amount (immutable; never recomputed here).
        $adjustmentKeys = $entries
            ->map(static fn (PlatformFeeLedgerEntry $e): string => substr((string) $e->idempotency_key, 7))
            ->all();
        /** @var Collection<string, int> $signedByKey */
        $signedByKey = PlatformFeeAdjustment::query()
            ->whereIn('idempotency_key', $adjustmentKeys)
            ->pluck('amount_minor', 'idempotency_key');

        $applied = 0;
        $residualCount = 0;
        $residualMinor = 0;
        /** @var Collection<int, PlatformFeeLedgerEntry> $consumed */
        $consumed = collect();

        foreach ($entries as $entry) {
            $key = substr((string) $entry->idempotency_key, 7);
            if (! $signedByKey->has($key)) {
                // No paired adjustment (never happens for a service-created correction) — skip defensively.
                continue;
            }
            $signed = (int) $signedByKey->get($key);

            // Consume iff the invoice stays non-negative afterwards; positive corrections always fit.
            if ($applied + $signed >= -$headroomMinor) {
                $applied += $signed;
                $consumed->push($entry);
            } else {
                $residualCount++;
                $residualMinor += $signed;
            }
        }

        return new PlatformFeeCorrectionSelection($applied, $consumed, $residualCount, $residualMinor);
    }

    /**
     * Phase 20E future-cycle closure — write the single signed `adjustment` line for the consumed
     * corrections, link every consumed entry, and transition it `pending → aggregated → invoiced`. Emits
     * `platform_fee.aggregated` + `platform_fee.invoiced` (the same aggregation events as the rollup — NOT
     * `platform_fee.adjusted`, which means "a correction was created"). Returns null (and writes nothing)
     * when the selection is empty or nets to zero, so no empty adjustment item is ever created.
     */
    public function writeCorrectionLine(
        SubscriptionInvoice $invoice,
        PlatformFeeCorrectionSelection $selection,
        ?User $actor,
    ): ?SubscriptionInvoiceItem {
        $this->assertInTransaction();

        if ($selection->isEmpty()) {
            return null;
        }

        $item = new SubscriptionInvoiceItem;
        $item->merchant_id = $invoice->merchant_id;
        $item->subscription_invoice_id = $invoice->id;
        $item->description = 'Platform fee adjustments';
        $item->amount_minor = $selection->netMinor; // signed; `adjustment` is the only negatable line type
        $item->type = SubscriptionInvoiceItemType::Adjustment;
        $item->save();

        $entryUlids = [];
        foreach ($selection->entries as $entry) {
            $this->ledgerMachine->ensure($entry->status, PlatformFeeLedgerStatus::Aggregated);
            $entry->forceFill([
                'subscription_invoice_item_id' => $item->id,
                'status' => PlatformFeeLedgerStatus::Aggregated->value,
            ])->save();

            $this->ledgerMachine->ensure(PlatformFeeLedgerStatus::Aggregated, PlatformFeeLedgerStatus::Invoiced);
            $entry->forceFill(['status' => PlatformFeeLedgerStatus::Invoiced->value])->save();

            $entryUlids[] = $entry->ulid;
        }

        $context = [
            'subscription_invoice_id' => $invoice->ulid,
            'subscription_invoice_item_id' => $item->ulid,
            'correction_net_minor' => $selection->netMinor,
            'entry_count' => $selection->count(),
            'residual_entry_count' => $selection->residualEntryCount,
            'residual_minor' => $selection->residualMinor,
            'currency' => $invoice->currency,
            'period_start' => $invoice->period_start->toDateString(),
            'period_end' => $invoice->period_end->toDateString(),
            'ledger_entry_ids' => $entryUlids,
        ];

        $this->audit->record(AuditEvent::PlatformFeeAggregated, $actor, $invoice->merchant_id, null, $item, $context);
        $this->audit->record(AuditEvent::PlatformFeeInvoiced, $actor, $invoice->merchant_id, null, $item, $context);

        return $item;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Platform-fee aggregation must run inside the subscription-invoice issuance transaction.');
        }
    }
}
