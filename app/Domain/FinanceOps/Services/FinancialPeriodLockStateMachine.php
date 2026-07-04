<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Services;

use App\Domain\FinanceOps\Enums\FinancialPeriodLockStatus;
use App\Domain\FinanceOps\Exceptions\FinancialPeriodLockException;

/**
 * Financial period lock transition guard (Plan §46; ADR-0007; Phase 18B). Every
 * status change goes through a named action calling {@see ensure()}; an unlisted
 * transition is rejected with `422 invalid_state_transition`. Mirrors the DB CHECK.
 * See docs/architecture/state-machines/financial-period-lock.md.
 */
final class FinancialPeriodLockStateMachine
{
    public function ensure(FinancialPeriodLockStatus $from, FinancialPeriodLockStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw FinancialPeriodLockException::invalidTransition($from, $to);
        }
    }
}
