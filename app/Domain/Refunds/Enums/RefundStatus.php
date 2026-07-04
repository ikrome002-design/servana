<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Enums;

use App\Domain\Refunds\Services\RefundStateMachine;

/**
 * External refund lifecycle (Plan §44; Phase 18B). Mirrors the refunds.status DB
 * CHECK. Status is never assigned directly; every change goes through a named action
 * via {@see RefundStateMachine}. See
 * docs/architecture/state-machines/refund.md.
 */
enum RefundStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Finalized = 'finalized';
    case Rejected = 'rejected';

    /**
     * Authoritative transition inventory (Plan §44). `approved -> rejected` is
     * permitted only where documented policy allows reversing an approval.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected],
            self::Approved => [self::Finalized, self::Rejected],
            self::Finalized, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
