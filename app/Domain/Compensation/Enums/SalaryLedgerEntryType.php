<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * The kind of a salary_ledger row (Plan §60; Phase 20G). Mirrors the salary_ledger.entry_type
 * DB CHECK; parity guarded by Phase20GEnumParityTest.
 */
enum SalaryLedgerEntryType: string
{
    case Accrual = 'accrual';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
