<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Salary cadence of a compensation plan (Plan §59, §60; Phase 20F). Mirrors the
 * PostgreSQL CHECK on `personnel_compensation_plans.salary_period`; parity guarded by
 * `Phase20FEnumParityTest`.
 *
 * Configuration only — the cadence declares how salary WILL be accrued. Phase 20F
 * accrues nothing; salary accrual is Phase 20G (`salary_ledger`).
 */
enum SalaryPeriod: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';
    case Daily = 'daily';
    case Hourly = 'hourly';
    case PerShift = 'per_shift';

    /**
     * All backing values, canonical order — authoritative for the DB CHECK and parity.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Weekly => 'Weekly',
            self::Daily => 'Daily',
            self::Hourly => 'Hourly',
            self::PerShift => 'Per shift',
        };
    }
}
