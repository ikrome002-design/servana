<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Exceptions\PaymentRecordingException;
use App\Domain\Payments\ValueObjects\PaymentComponentInput;

/**
 * Validates group composition and returns the derived group total (Plan §41;
 * Phase 18A). Requires ≥1 component, every amount positive, every method concrete
 * (Gate B), and a single currency equal to the invoice currency. The group total is
 * DERIVED here (Σ components) — never accepted from the browser — so group total =
 * component sum holds by construction.
 *
 * @phpstan-param list<PaymentComponentInput> $components
 */
final class PaymentGroupTotalsValidator
{
    /**
     * @param  list<PaymentComponentInput>  $components
     * @return int the derived group total in integer minor units
     */
    public function validateAndTotal(array $components, string $invoiceCurrency): int
    {
        if ($components === []) {
            throw PaymentRecordingException::emptyGroup();
        }

        $total = 0;

        foreach ($components as $component) {
            if (! $component->method->isConcreteComponentMethod()) {
                throw PaymentRecordingException::invalidComponentMethod();
            }

            if ($component->amountMinor <= 0) {
                throw PaymentRecordingException::nonPositiveAmount();
            }

            if ($component->currency !== null && strtoupper($component->currency) !== strtoupper($invoiceCurrency)) {
                throw PaymentRecordingException::mixedCurrency();
            }

            $total += $component->amountMinor;
        }

        return $total;
    }
}
