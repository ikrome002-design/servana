<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Enums;

use App\Domain\Scheduling\Services\AppointmentStateMachine;

/**
 * Appointment lifecycle states (Plan §25.2; Phase 16A subset). Mirrors the DB
 * CHECK. `queued` (16B) and `in_service` (16C) are deferred and added by
 * expand-and-contract in their owning phases — they are intentionally NOT here.
 *
 * Status is never assigned directly; every change goes through a named domain
 * action via {@see AppointmentStateMachine}.
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case CancelledWithReason = 'cancelled_with_reason';
    case NoShow = 'no_show';

    /**
     * Statuses that reserve a personnel member's time (participate in the DB
     * double-booking exclusion). Terminal/non-reserving states free the interval.
     *
     * @return list<self>
     */
    public static function reservingStatuses(): array
    {
        return [self::Scheduled, self::Confirmed, self::CheckedIn];
    }

    /** Whether this status reserves personnel time (blocks overlapping bookings). */
    public function reservesTime(): bool
    {
        return in_array($this, self::reservingStatuses(), true);
    }

    /** Terminal states cannot be reopened or edited. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::CancelledWithReason, self::NoShow => true,
            default => false,
        };
    }

    /**
     * Authoritative Phase-16A transition inventory (Plan §25.2). Every legal
     * next-state for the current state; anything else is invalid.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::CheckedIn, self::Rescheduled, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::CancelledWithReason],
            self::Rescheduled => [self::Scheduled, self::Confirmed],
            self::Cancelled, self::CancelledWithReason, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
