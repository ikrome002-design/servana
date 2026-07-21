<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;

/**
 * Personnel payout-run status-machine guard (Plan §62; §25.4/§25.5; Phase 20H). Authorizes a
 * `personnel_payout_runs.status` transition; the inventory lives on
 * {@see PayoutRunStatus::allowedTransitions()}. An unlisted transition raises
 * {@see CompensationStateException} → `422 invalid_state_transition`.
 *
 * Payout items always mirror the run status inside the same transaction, so this guard is the single
 * authority for the run lifecycle.
 */
final class PayoutRunStateMachine
{
    public function canTransition(PayoutRunStatus $from, PayoutRunStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws CompensationStateException
     */
    public function ensure(PayoutRunStatus $from, PayoutRunStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw CompensationStateException::invalidTransition('personnel payout run', $from->value, $to->value);
        }
    }
}
