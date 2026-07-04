<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Enums;

/**
 * Finance dispute lifecycle (Plan §44; Phase 18B). Mirrors the finance_disputes.status
 * DB CHECK. Authoritative Plan 4-state set (the broader Scope-only list is not added).
 * See docs/architecture/state-machines/finance-dispute.md.
 */
enum FinanceDisputeStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::UnderReview, self::Rejected],
            self::UnderReview => [self::Resolved, self::Rejected],
            self::Resolved, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
