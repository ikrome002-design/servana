<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Percentage platform-fee ledger entry type (Plan §13.10; Phase 20E). `earned` is the
 * original liability created at Finance validation; `reversal`/`adjustment` are ADDITIVE
 * rows that never rewrite the original monetary fact. Mirrors the PostgreSQL CHECK on
 * `platform_fee_ledger_entries.entry_type`. Parity guarded by `Phase20EEnumParityTest`.
 */
enum PlatformFeeEntryType: string
{
    case Earned = 'earned';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Earned => 'Earned',
            self::Reversal => 'Reversal',
            self::Adjustment => 'Adjustment',
        };
    }
}
