<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\PlatformFeeLedgerStatus;
use App\Domain\Billing\Exceptions\BillingStateException;

/**
 * Platform-fee ledger-entry status-machine guard (Plan §13.10, §51; Phase 20E). The single place that
 * authorizes a `platform_fee_ledger_entries.status` transition; the inventory lives on
 * {@see PlatformFeeLedgerStatus::allowedTransitions()}. Every lifecycle change (aggregation, reversal,
 * adjustment) runs through here; an unlisted transition raises {@see BillingStateException} → `422
 * invalid_state_transition`. Monetary/snapshot columns are immutable at the database (append-only
 * trigger); only `status` and the aggregation link transition. See
 * docs/architecture/state-machines/platform-fee-ledger-entry.md.
 */
final class PlatformFeeLedgerEntryStateMachine
{
    public function canTransition(PlatformFeeLedgerStatus $from, PlatformFeeLedgerStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws BillingStateException
     */
    public function ensure(PlatformFeeLedgerStatus $from, PlatformFeeLedgerStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw BillingStateException::invalidTransition('platform fee ledger entry', $from->value, $to->value);
        }
    }
}
