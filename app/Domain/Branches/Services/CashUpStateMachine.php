<?php

declare(strict_types=1);

namespace App\Domain\Branches\Services;

use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Branches\Exceptions\CashUpException;

/**
 * Cash-up transition guard (Plan §45; Phase 18B). Every status change goes through a
 * named action calling {@see ensure()}; an unlisted transition is rejected with
 * `422 invalid_state_transition`. Mirrors the DB CHECK. See
 * docs/architecture/state-machines/cash-up.md.
 */
final class CashUpStateMachine
{
    public function ensure(CashUpStatus $from, CashUpStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw CashUpException::invalidTransition($from, $to);
        }
    }
}
