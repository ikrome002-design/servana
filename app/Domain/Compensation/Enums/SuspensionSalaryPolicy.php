<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Whether salary continues to accrue while a personnel member is suspended (Plan A-11; §60;
 * Phase 20G — added by the forward-only expand migration
 * `2026_07_17_000005_add_suspension_salary_policy_...`, because Phase 20F shipped the
 * compensation-plan table without this §13.12 column). Mirrors the
 * personnel_compensation_plans.suspension_salary_policy DB CHECK; parity guarded by
 * Phase20GEnumParityTest.
 *
 * `continue` is the settled default (A-11): salary accrues normally during suspension. A
 * merchant may set a PROSPECTIVE `pause` override only by superseding the plan to a new
 * effective-dated version — it never retroactively rewrites accrued salary. The salary
 * segmenter treats a `pause` plan-version window as non-payable.
 */
enum SuspensionSalaryPolicy: string
{
    case Continue = 'continue';
    case Pause = 'pause';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    /** Salary accrues during a suspension under this policy. */
    public function accruesDuringSuspension(): bool
    {
        return $this === self::Continue;
    }
}
