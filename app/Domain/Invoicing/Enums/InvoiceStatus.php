<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use App\Domain\Invoicing\Services\InvoiceStateMachine;

/**
 * Merchant-Client Invoice lifecycle states (Plan §25.3, §13.8, §40; Phase 17).
 * Mirrors the DB CHECK.
 *
 * Status is never assigned directly; every change goes through a named domain
 * action via {@see InvoiceStateMachine}. Phase 17 reaches only
 * `draft → issued → void_pending → voided|issued` and `issued → adjusted`; the
 * payment-driven and post-payment transitions are defined and unit-tested here but
 * are Phase-18B-driven (no Phase 17 endpoint can make an invoice `paid`).
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case VoidPending = 'void_pending';
    case Voided = 'voided';
    case Adjusted = 'adjusted';
    case RefundPending = 'refund_pending';
    case AdjustmentRequired = 'adjustment_required';

    /**
     * Payable states from which a void may be requested; also the states a
     * void_pending rejection may restore to.
     *
     * @return list<self>
     */
    public static function payableStatuses(): array
    {
        return [self::Issued, self::PartiallyPaid];
    }

    /** Terminal/correction states with no onward transition. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Voided, self::Adjusted, self::AdjustmentRequired => true,
            // refund_pending is NOT terminal in Phase 18B — it resolves on reject/finalize (§44).
            default => false,
        };
    }

    /** Whether monetary snapshots + the invoice number are frozen (finalized). */
    public function isFinalized(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Authoritative Phase-17 transition inventory (Plan §25.3). Every legal
     * next-state for the current state; anything else is invalid.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Issued],
            self::Issued => [self::PartiallyPaid, self::Paid, self::VoidPending, self::Adjusted],
            // Phase 18B: a partially-paid invoice may also enter refund_pending (§44).
            self::PartiallyPaid => [self::Paid, self::VoidPending, self::Adjusted, self::RefundPending],
            self::VoidPending => [self::Voided, self::Issued, self::PartiallyPaid],
            self::Paid => [self::RefundPending, self::AdjustmentRequired],
            // Phase 18B: refund_pending resolves back to a derived payable/paid state on
            // rejection (restore) or finalization (derive from validated_paid) (§44).
            self::RefundPending => [self::Issued, self::PartiallyPaid, self::Paid],
            self::Voided, self::Adjusted, self::AdjustmentRequired => [],
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
