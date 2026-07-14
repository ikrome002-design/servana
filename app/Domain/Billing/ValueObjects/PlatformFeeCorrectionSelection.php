<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Billing\Services\AggregatePlatformFeesIntoSubscriptionInvoice;
use Illuminate\Support\Collection;

/**
 * Immutable result of selecting + capping the eligible pending platform-fee CORRECTION entries
 * (`entry_type IN ('reversal','adjustment')`) for a single merchant + currency, swept forward into the
 * next subscription invoice (Plan §13.10, §51, §953; ADR-005; Phase 20E future-cycle closure). Produced
 * by {@see AggregatePlatformFeesIntoSubscriptionInvoice::collectApplicableCorrections()} before the
 * invoice is created so the immutable subtotal can already include the signed net.
 *
 * `netMinor` is the SIGNED sum of the CONSUMED correction entries (each entry's canonical signed value is
 * `platform_fee_adjustments.amount_minor`, never recomputed). It is capped so the invoice total can never
 * go negative (DB `subscription_invoices.total_minor >= 0`; no Wallet credit — that is Phase 20D-W): a
 * negative correction that would breach the floor is NOT consumed and its whole entry stays `pending` for
 * a later cycle (`residual*`). Entry-level granularity — an immutable correction row is never split or
 * partially mutated. The consumed entries are row-locked (`FOR UPDATE`) inside the issuance transaction,
 * in the deterministic `billable_at ASC, ulid ASC` order.
 */
final class PlatformFeeCorrectionSelection
{
    /**
     * @param  Collection<int, PlatformFeeLedgerEntry>  $entries  the CONSUMED correction entries (to be
     *                                                            linked + transitioned pending→aggregated→invoiced)
     */
    public function __construct(
        public readonly int $netMinor,
        public readonly Collection $entries,
        public readonly int $residualEntryCount,
        public readonly int $residualMinor,
    ) {}

    /**
     * A correction line is written only when at least one entry is consumed AND the signed net is
     * non-zero (an empty adjustment item is never created; a net-zero consumed set — e.g. an exact
     * +X/-X cancellation — is deferred whole to a later cycle).
     */
    public function isEmpty(): bool
    {
        return $this->entries->isEmpty() || $this->netMinor === 0;
    }

    public function count(): int
    {
        return $this->entries->count();
    }
}
