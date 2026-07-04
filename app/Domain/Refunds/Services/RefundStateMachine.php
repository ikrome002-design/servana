<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Services;

use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Exceptions\RefundException;

/**
 * Refund transition guard (Plan §44; Phase 18B). Every status change goes through a
 * named action calling {@see ensure()}; an unlisted transition is rejected with
 * `422 invalid_state_transition`. See docs/architecture/state-machines/refund.md.
 */
final class RefundStateMachine
{
    public function ensure(RefundStatus $from, RefundStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw RefundException::invalidTransition($from, $to);
        }
    }
}
