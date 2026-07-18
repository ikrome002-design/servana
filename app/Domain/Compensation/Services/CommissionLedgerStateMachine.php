<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Enums\CommissionLedgerStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;

/**
 * Commission-ledger status-machine guard (Plan §61; Phase 20G). The single place that authorizes a
 * `commission_ledger.status` transition; the inventory lives on
 * {@see CommissionLedgerStatus::allowedTransitions()}. An unlisted transition raises
 * {@see CompensationStateException} → `422 invalid_state_transition`.
 *
 * Only `status` (and the Phase 20H `payout_item_id` link) ever change — every monetary/snapshot
 * column is immutable at the database (append-only trigger); a correction is an ADDITIVE reversal or
 * adjustment row, never an edit. `included_in_payout`/`paid` transitions exist for schema parity but
 * are driven by Phase 20H, not Phase 20G.
 */
final class CommissionLedgerStateMachine
{
    public function canTransition(CommissionLedgerStatus $from, CommissionLedgerStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws CompensationStateException
     */
    public function ensure(CommissionLedgerStatus $from, CommissionLedgerStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw CompensationStateException::invalidTransition('commission ledger entry', $from->value, $to->value);
        }
    }
}
