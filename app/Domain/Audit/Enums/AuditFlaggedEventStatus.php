<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

/**
 * Flagged audit-event review lifecycle (Plan §13.2, §25; Phase 19). Mirrors the
 * audit_flagged_events.status DB CHECK. Only review METADATA transitions through this
 * machine — the source audit_logs row remains immutable and hash-chain protected.
 * See docs/architecture/state-machines/audit-flagged-event.md.
 *
 * Lifecycle:
 *   open        --review-->  under_review
 *   reopened    --review-->  under_review
 *   under_review --resolve-> resolved
 *   under_review --dismiss-> dismissed
 *   resolved    --reopen-->  reopened
 *   dismissed   --reopen-->  reopened
 */
enum AuditFlaggedEventStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
    case Reopened = 'reopened';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::UnderReview],
            self::Reopened => [self::UnderReview],
            self::UnderReview => [self::Resolved, self::Dismissed],
            self::Resolved => [self::Reopened],
            self::Dismissed => [self::Reopened],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** A terminal review outcome (still reopenable, but carries a resolver + notes). */
    public function isResolvedOrDismissed(): bool
    {
        return $this === self::Resolved || $this === self::Dismissed;
    }
}
