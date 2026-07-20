<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Lifecycle status of a commission_ledger row (Plan §61; Phase 20G). Mirrors the
 * commission_ledger.status DB CHECK; parity guarded by Phase20GEnumParityTest.
 *
 * `earned` rows are available for a future Phase 20H payout; `included_in_payout`/`paid` are
 * Phase 20H transitions; `reversed`/`adjusted` mark an original that a later additive row
 * offsets. Reversal/adjustment rows are themselves realized and carry `earned`.
 */
enum CommissionLedgerStatus: string
{
    case Pending = 'pending';
    case Earned = 'earned';
    case IncludedInPayout = 'included_in_payout';
    case Paid = 'paid';
    case Reversed = 'reversed';
    case Adjusted = 'adjusted';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** A monetary fact that is already settled to a personnel member (Phase 20H paid). */
    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    /**
     * Authoritative status transitions (docs/architecture/state-machines/commission-ledger-entry.md).
     * `included_in_payout`/`paid` are Phase 20H transitions — defined for schema parity, never
     * invoked in Phase 20G. Monetary columns never change (append-only DB guard); only `status` moves.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Earned, self::Cancelled],
            self::Earned => [self::IncludedInPayout, self::Reversed, self::Adjusted, self::Cancelled],
            self::IncludedInPayout => [self::Paid, self::Reversed],
            self::Paid, self::Reversed, self::Adjusted, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
