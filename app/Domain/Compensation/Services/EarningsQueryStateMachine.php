<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Enums\EarningsQueryStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;

/**
 * Earnings-query status-machine guard (Plan §63; §25.4; Phase 20H). Authorizes an
 * `earnings_queries.status` transition; the inventory lives on
 * {@see EarningsQueryStatus::allowedTransitions()}. An unlisted transition raises
 * {@see CompensationStateException} → `422 invalid_state_transition`.
 *
 * Resolution NEVER mutates a ledger silently — a monetary correction is a separate
 * `compensation_adjustments` row referenced by `resolved_adjustment_id`.
 */
final class EarningsQueryStateMachine
{
    public function canTransition(EarningsQueryStatus $from, EarningsQueryStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws CompensationStateException
     */
    public function ensure(EarningsQueryStatus $from, EarningsQueryStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw CompensationStateException::invalidTransition('earnings query', $from->value, $to->value);
        }
    }
}
