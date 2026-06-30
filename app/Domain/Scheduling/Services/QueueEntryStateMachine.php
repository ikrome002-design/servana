<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Exceptions\QueueEntryStateException;

/**
 * Queue-entry state-machine guard (Plan §25.1/§25.2, §37; Phase 16B).
 *
 * THE single place that authorizes a queue-entry status transition. Domain
 * actions call {@see ensure()} before writing; the transition inventory lives on
 * {@see QueueEntryStatus::allowedTransitions()}. There is no generic
 * `PATCH status` — every transition has a named action and runs through here.
 */
final class QueueEntryStateMachine
{
    public function canTransition(QueueEntryStatus $from, QueueEntryStatus $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * Assert a transition is legal or throw the canonical 422 envelope.
     *
     * @throws QueueEntryStateException
     */
    public function ensure(QueueEntryStatus $from, QueueEntryStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw QueueEntryStateException::invalidTransition($from, $to);
        }
    }
}
