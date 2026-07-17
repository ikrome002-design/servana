<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * How a personnel earns (Plan §59; Scope §12.2; Phase 20F, F1). Mirrors the PostgreSQL
 * CHECK on `personnel_compensation_plans.compensation_model`; parity guarded by
 * `Phase20FEnumParityTest`.
 *
 * DISTINCT from `staff_profiles.employment_type` (Scope §12.2 forbids overloading the two):
 * the same label in two different columns does not make the columns interchangeable.
 */
enum CompensationModel: string
{
    case CommissionOnly = 'commission_only';
    case SalaryPlusCommission = 'salary_plus_commission';
    case SalaryOnly = 'salary_only';

    /**
     * All backing values, canonical order — authoritative for the DB CHECK and parity.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }

    /** Salary terms are required by this model (DB model-shape CHECK). */
    public function requiresSalary(): bool
    {
        return match ($this) {
            self::SalaryOnly, self::SalaryPlusCommission => true,
            self::CommissionOnly => false,
        };
    }

    /**
     * A commission rule is required by this model. `salary_only` NEVER has one — the DB
     * CHECK keeps `commission_rule_id` NULL, so no rule can ever resolve for that
     * personnel (Plan §80 named test; Scope §12.5).
     */
    public function requiresCommissionRule(): bool
    {
        return match ($this) {
            self::CommissionOnly, self::SalaryPlusCommission => true,
            self::SalaryOnly => false,
        };
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::CommissionOnly => 'Commission only',
            self::SalaryPlusCommission => 'Salary plus commission',
            self::SalaryOnly => 'Salary only',
        };
    }
}
