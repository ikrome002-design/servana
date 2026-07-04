<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Services;

use App\Domain\FinanceOps\Enums\FinanceDisputeStatus;
use App\Domain\FinanceOps\Exceptions\FinanceDisputeException;

/**
 * Finance-dispute transition guard (Plan §44; Phase 18B). Every status change goes
 * through a named action calling {@see ensure()}; an unlisted transition is rejected
 * with `422 invalid_state_transition`. See docs/architecture/state-machines/finance-dispute.md.
 */
final class FinanceDisputeStateMachine
{
    public function ensure(FinanceDisputeStatus $from, FinanceDisputeStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw FinanceDisputeException::invalidTransition($from, $to);
        }
    }
}
