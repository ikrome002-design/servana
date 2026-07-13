<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

use App\Domain\Billing\Models\PlatformFeeLedgerEntry;
use App\Domain\Billing\Services\AggregatePlatformFeesIntoSubscriptionInvoice;
use Illuminate\Support\Collection;

/**
 * Immutable result of selecting the eligible earned/pending platform-fee ledger entries for a single
 * merchant + currency + Africa/Nairobi billing period (Plan §51; Phase 20E, Increment 5A). Produced by
 * {@see AggregatePlatformFeesIntoSubscriptionInvoice::collectEligible()}
 * before the subscription invoice is created, so the immutable subtotal can already include the rollup.
 * The entries are row-locked (`FOR UPDATE`) inside the issuance transaction, in the deterministic
 * `billable_at ASC, ulid ASC` order.
 */
final class PlatformFeeRollupSelection
{
    /**
     * @param  Collection<int, PlatformFeeLedgerEntry>  $entries
     */
    public function __construct(
        public readonly int $totalMinor,
        public readonly Collection $entries,
    ) {}

    public function isEmpty(): bool
    {
        return $this->entries->isEmpty();
    }

    public function count(): int
    {
        return $this->entries->count();
    }
}
