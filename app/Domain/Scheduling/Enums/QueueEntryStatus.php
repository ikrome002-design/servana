<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Enums;

use App\Domain\Scheduling\Services\QueueEntryStateMachine;

/**
 * Queue Entry lifecycle states (Plan §25.2, §37; Phase 16B). Mirrors the DB CHECK.
 *
 * Status is never assigned directly; every change goes through a named domain
 * action via {@see QueueEntryStateMachine}. `in_service`/`completed` are queue
 * states here — Phase 16B creates NO service session/invoice; Phase 16C couples a
 * service session transactionally onto `called → in_service` / `in_service →
 * completed`.
 */
enum QueueEntryStatus: string
{
    case Waiting = 'waiting';
    case Assigned = 'assigned';
    case Called = 'called';
    case InService = 'in_service';
    case Completed = 'completed';
    case Transferred = 'transferred';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /**
     * Active states that occupy the queue, block branch day-close / archival, and
     * (where ordered) hold a branch position. `transferred` is transient but still
     * active until it resolves.
     *
     * @return list<self>
     */
    public static function activeStatuses(): array
    {
        return [self::Waiting, self::Assigned, self::Called, self::InService, self::Transferred];
    }

    /**
     * The ordered active states that carry a unique, contiguous, positive branch
     * position (the partial-unique index covers exactly these).
     *
     * @return list<self>
     */
    public static function orderedActiveStatuses(): array
    {
        return [self::Waiting, self::Assigned, self::Called];
    }

    /** Whether this status occupies the queue (blocks day close / archival). */
    public function isActive(): bool
    {
        return in_array($this, self::activeStatuses(), true);
    }

    /** Whether this status participates in branch position ordering. */
    public function isOrderedActive(): bool
    {
        return in_array($this, self::orderedActiveStatuses(), true);
    }

    /** Terminal states cannot be reopened or edited. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled, self::NoShow => true,
            default => false,
        };
    }

    /**
     * Authoritative Phase-16B transition inventory (Plan §25.2). Every legal
     * next-state for the current state; anything else is invalid.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Waiting => [self::Assigned, self::Transferred, self::Cancelled, self::NoShow],
            self::Assigned => [self::Called, self::Transferred, self::Cancelled, self::NoShow],
            self::Called => [self::InService, self::Transferred, self::Cancelled, self::NoShow],
            self::InService => [self::Completed],
            self::Transferred => [self::Assigned, self::Waiting],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
