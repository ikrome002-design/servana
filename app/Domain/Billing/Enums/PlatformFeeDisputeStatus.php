<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Percentage platform-fee dispute status (Plan §13.10 [Correction 3]; Phase 20E). The
 * active Plan deliberately narrows the set to these four — there is NO `escalated` state.
 * Mirrors the PostgreSQL CHECK on `platform_fee_disputes.status`. Parity guarded by
 * `Phase20EEnumParityTest`. A money-changing resolution creates a `platform_fee_adjustments`
 * row; it never rewrites a ledger amount.
 */
enum PlatformFeeDisputeStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return $this === self::Resolved || $this === self::Rejected;
    }

    /**
     * Allowed lifecycle transitions (Plan §13.10 [Correction 3]; Phase 20E). See
     * docs/architecture/state-machines/platform-fee-dispute.md.
     *
     * @return list<self>
     */
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

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::UnderReview => 'Under review',
            self::Resolved => 'Resolved',
            self::Rejected => 'Rejected',
        };
    }
}
