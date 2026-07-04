<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Enums;

use App\Domain\FinanceOps\Services\FinancialPeriodLockStateMachine;

/**
 * Financial period lock lifecycle (Plan §46; ADR-0007; Phase 18B). Mirrors the
 * financial_period_locks.status DB CHECK. Status is never assigned directly; every
 * change goes through a named action via
 * {@see FinancialPeriodLockStateMachine}. See
 * docs/architecture/state-machines/financial-period-lock.md.
 */
enum FinancialPeriodLockStatus: string
{
    case Open = 'open';
    case Locked = 'locked';
    case Reopened = 'reopened';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Locked],
            self::Locked => [self::Reopened],
            self::Reopened => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
