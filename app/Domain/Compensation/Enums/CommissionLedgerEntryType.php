<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * The kind of a commission_ledger row (Plan §61; Phase 20G). Mirrors the
 * commission_ledger.entry_type DB CHECK; parity guarded by Phase20GEnumParityTest.
 *
 * `pending_preview` is a canonical value carried for completeness — Phase 16C computes
 * non-payable previews on the fly (no ledger row), so Phase 20G does not persist preview
 * rows. `earned` is created only at Finance validation; `reversal` is the exact negative of
 * an original earned row; `adjustment` rows are additive.
 */
enum CommissionLedgerEntryType: string
{
    case PendingPreview = 'pending_preview';
    case Earned = 'earned';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
