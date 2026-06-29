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
 *
 * `busy` is intentionally absent in 15B — it depends on live queue/service-session
 * aggregates owned by Phases 16B/16C.
 */
enum PersonnelAvailabilityState: string
{
    case Suspended = 'suspended';
    case Available = 'available';
    case OnBreak = 'on_break';
    case Unavailable = 'unavailable';
    case Offline = 'offline';
}
