<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

/**
 * Audit-event domain classification (Plan §19.2 Audit read segmentation; Phase 19).
 *
 * The canonical Audit read keys are split by domain — `audit.branch_events.view`
 * (general branch operations), `audit.finance.view` / `finance.audit.view`
 * (finance domain), and `audit.compensation.view` (compensation domain). This
 * enum is the single source that maps each typed {@see AuditEvent} to its read
 * segment so the masked read endpoints filter server-side by domain (never by a
 * client-supplied predicate).
 *
 * `Compensation` is intentionally unpopulated until its owning phases (20F–20H)
 * emit compensation events; the compensation read surface therefore returns an
 * empty (but authorized) result today rather than fabricating events.
 */
enum AuditDomain: string
{
    case General = 'general';
    case Finance = 'finance';
    case Compensation = 'compensation';
}
