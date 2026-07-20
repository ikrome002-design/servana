<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * The kind of a compensation_adjustments row (Plan §60/§61; Phase 20G). Mirrors the
 * compensation_adjustments.adjustment_type DB CHECK; parity guarded by Phase20GEnumParityTest.
 *
 * `manual` — a Finance-created additive adjustment (`compensation.adjustment.create`, MFA +
 * fresh step-up). `paid_commission_reversal` / `paid_salary_reversal` — a system-created
 * negative adjustment offsetting an ALREADY-PAID ledger row (paid history is never rewritten;
 * Plan §61). `correction` — reserved for a documented Finance correction.
 */
enum CompensationAdjustmentType: string
{
    case Manual = 'manual';
    case PaidCommissionReversal = 'paid_commission_reversal';
    case PaidSalaryReversal = 'paid_salary_reversal';
    case Correction = 'correction';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
