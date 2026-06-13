<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Supported currencies. KES is the only launch currency (Plan AS-3); the enum
 * exists so the schema's char(3) currency column and the Money value object are
 * forward-compatible without code churn.
 */
enum Currency: string
{
    case KES = 'KES';
    // Forward-compatibility only (Plan AS-3). KES is the sole launch currency;
    // USD lets the Money value object enforce and exercise currency safety.
    case USD = 'USD';

    /** Minor units per major unit (e.g. 100 cents = 1 shilling). */
    public function minorUnitScale(): int
    {
        return match ($this) {
            self::KES, self::USD => 100,
        };
    }

    /** Number of fractional digits shown when formatting. */
    public function fractionDigits(): int
    {
        return match ($this) {
            self::KES, self::USD => 2,
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::KES => 'KES',
            self::USD => 'USD',
        };
    }
}
