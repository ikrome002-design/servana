<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Enums;

use App\Domain\Scheduling\Services\ServiceSessionStateMachine;

/**
 * Service Session lifecycle states (Plan §25.2, §13.7; Phase 16C). Mirrors the DB
 * CHECK.
 *
 * Status is never assigned directly; every change goes through a named domain
 * action via {@see ServiceSessionStateMachine}. A queue-linked session is created
 * `pending` and started (`in_progress`) atomically — `pending` is transient on the
 * queue path. `in_progress` projects the assigned personnel as `busy`; completion
 * yields a NON-PAYABLE commission preview only (no invoice/ledger in 16C).
 */
enum ServiceSessionStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Active (work-occupying) states: block branch day-close / archival and project
     * personnel `busy`. The duplicate-active partial-unique index covers exactly
     * these.
     *
     * @return list<self>
     */
    public static function activeStatuses(): array
    {
        return [self::Pending, self::InProgress];
    }

    /** Whether this status occupies the personnel (blocks day close / archival, projects busy). */
    public function isActive(): bool
    {
        return in_array($this, self::activeStatuses(), true);
    }

    /** Terminal states cannot be reopened or edited. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Authoritative Phase-16C transition inventory (Plan §25.2). Every legal
     * next-state for the current state; anything else is invalid.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * @param  list<self>  $statuses
     * @return list<string>
     */
    public static function values(array $statuses): array
    {
        return array_map(static fn (self $s): string => $s->value, $statuses);
    }
}
