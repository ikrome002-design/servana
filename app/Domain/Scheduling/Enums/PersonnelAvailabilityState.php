<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Enums;

/**
 * Derived, read-only current availability state (Plan §13.7, Scope §3.4; Phase
 * 15B). Computed by AvailabilityResolver; never persisted.
 *
 *   - Suspended:   staff lifecycle is inactive (is_active=false) — outranks any
 *                  schedule row.
 *   - Available:   now is fully inside an effective available window.
 *   - OnBreak:     now is inside an effective unavailable break that sits within
 *                  an otherwise-scheduled working period.
 *   - Unavailable: an explicit recurring/exact-date unavailable interval applies
 *                  outside a working period.
 *   - Offline:     outside all effective available windows (e.g. day off, before/
 *                  after shift, no recurring schedule).
 *   - Busy:        DERIVED overlay (Phase 16C) — the personnel member has an
 *                  in-progress service session right now. Projected by
 *                  PersonnelStateProjector on top of the schedule-derived state
 *                  (it outranks Available); cleared when the session completes or is
 *                  resolved-cancelled. It is derived, never stored, and a frontend
 *                  toggle cannot override an active session.
 */
enum PersonnelAvailabilityState: string
{
    case Suspended = 'suspended';
    case Available = 'available';
    case OnBreak = 'on_break';
    case Unavailable = 'unavailable';
    case Offline = 'offline';
    case Busy = 'busy';
}
