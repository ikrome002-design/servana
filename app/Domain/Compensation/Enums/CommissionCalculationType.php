<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Commission value shape (Plan §59; Scope §12.7 Step 3A; Phase 20F, F4). Mirrors the
 * PostgreSQL CHECK on `commission_rules.calculation_type`; parity guarded by
 * `Phase20FEnumParityTest`.
 *
 * Exactly one calculation value is carried (DB value-shape CHECK): a percentage rule
 * carries integer basis points; a fixed rule carries integer minor units + currency.
 * Never float (Guardrail 6).
 */
enum CommissionCalculationType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';

    /**
     * All backing values, canonical order — authoritative for the DB CHECK and parity.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::FixedAmount => 'Fixed amount',
        };
    }
}
