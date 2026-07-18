<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Enums\SalaryLedgerStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;

/**
 * Salary-ledger status-machine guard (Plan §60; Phase 20G). Authorizes a `salary_ledger.status`
 * transition; the inventory lives on {@see SalaryLedgerStatus::allowedTransitions()}. An unlisted
 * transition raises {@see CompensationStateException} → `422 invalid_state_transition`.
 *
 * Only `status` (and the Phase 20H `payout_item_id` link) ever change; monetary/period columns are
 * immutable at the database. Corrections are additive reversal/adjustment rows.
 * `included_in_payout`/`paid` transitions exist for schema parity but are driven by Phase 20H.
 */
final class SalaryLedgerStateMachine
{
    public function canTransition(SalaryLedgerStatus $from, SalaryLedgerStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws CompensationStateException
     */
    public function ensure(SalaryLedgerStatus $from, SalaryLedgerStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw CompensationStateException::invalidTransition('salary ledger entry', $from->value, $to->value);
        }
    }
}
