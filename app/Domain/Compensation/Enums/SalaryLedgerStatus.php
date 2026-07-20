<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Lifecycle status of a salary_ledger row (Plan §60; Phase 20G). Mirrors the
 * salary_ledger.status DB CHECK; parity guarded by Phase20GEnumParityTest.
 *
 * A fresh accrual is `pending` (available for a future Phase 20H payout);
 * `included_in_payout`/`paid` are Phase 20H transitions; `reversed`/`adjusted` mark an original
 * an additive row offsets.
 */
enum SalaryLedgerStatus: string
{
    case Pending = 'pending';
    case IncludedInPayout = 'included_in_payout';
    case Paid = 'paid';
    case Reversed = 'reversed';
    case Adjusted = 'adjusted';

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
     * Authoritative status transitions (docs/architecture/state-machines/salary-ledger-entry.md).
     * `included_in_payout`/`paid` are Phase 20H transitions — defined for schema parity, never
     * invoked in Phase 20G. Monetary/period columns never change (append-only DB guard).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::IncludedInPayout, self::Reversed, self::Adjusted],
            self::IncludedInPayout => [self::Paid, self::Reversed],
            self::Paid, self::Reversed, self::Adjusted => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
