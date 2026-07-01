<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\ValueObjects\InvoiceTotals;
use App\Enums\Currency;
use App\Support\Money;

/**
 * Deterministic invoice-totals calculator (Phase 17). Integer minor units only via
 * the {@see Money} value object — never float. Encodes the data-dictionary formula:
 *
 *   subtotal      = Σ line_total_minor
 *   preferred_fee = Σ preferred_personnel_fee_minor   (null when no item has a fee)
 *   total         = subtotal + preferred_fee + tax - discount
 */
final class InvoiceTotalsCalculator
{
    /**
     * @param  list<array{line_total_minor: int, preferred_personnel_fee_minor: int|null}>  $lines
     */
    public function compute(array $lines, int $taxMinor, int $discountMinor, Currency $currency): InvoiceTotals
    {
        $subtotal = Money::zero($currency);
        $preferredTotal = Money::zero($currency);
        $hasPreferredFee = false;

        foreach ($lines as $line) {
            $subtotal = $subtotal->add(Money::ofMinor($line['line_total_minor'], $currency));

            if ($line['preferred_personnel_fee_minor'] !== null) {
                $hasPreferredFee = true;
                $preferredTotal = $preferredTotal->add(Money::ofMinor($line['preferred_personnel_fee_minor'], $currency));
            }
        }

        $tax = Money::ofMinor($taxMinor, $currency);
        $discount = Money::ofMinor($discountMinor, $currency);

        $total = $subtotal->add($preferredTotal)->add($tax)->subtract($discount);

        return new InvoiceTotals(
            subtotalMinor: $subtotal->minorUnits,
            preferredFeeTotalMinor: $hasPreferredFee ? $preferredTotal->minorUnits : null,
            taxMinor: $taxMinor,
            discountMinor: $discountMinor,
            totalMinor: $total->minorUnits,
            currency: $currency,
        );
    }
}
