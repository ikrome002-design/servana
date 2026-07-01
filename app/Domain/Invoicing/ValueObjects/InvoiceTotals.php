<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\ValueObjects;

use App\Domain\Invoicing\Services\InvoiceTotalsCalculator;
use App\Enums\Currency;

/**
 * Deterministic invoice header totals in integer minor units (Phase 17). Produced
 * by {@see InvoiceTotalsCalculator} from locked
 * authoritative line data — never browser-supplied. `preferredFeeTotalMinor` is
 * null when no item carries a preferred-personnel fee (distinguished from 0).
 */
final readonly class InvoiceTotals
{
    public function __construct(
        public int $subtotalMinor,
        public ?int $preferredFeeTotalMinor,
        public int $taxMinor,
        public int $discountMinor,
        public int $totalMinor,
        public Currency $currency,
    ) {}
}
