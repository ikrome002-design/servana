<?php

declare(strict_types=1);

namespace App\Domain\Compensation\ValueObjects;

use App\Domain\Compensation\Services\SalaryProrationCalculator;
use Carbon\CarbonImmutable;

/**
 * One payable salary segment inside a single pay period (Plan §60; G8 Actual/Actual proration).
 * Immutable input to {@see SalaryProrationCalculator}. All dates
 * are Africa/Nairobi business days; amounts integer minor units.
 *
 * `denominator` is the pay period's day count (actual days in the Nairobi month for monthly; 7 for
 * a weekly ISO week) and is uniform across every segment of one period. `payableDays` is the number
 * of calendar days this segment is payable within the period. The exact rational contribution is
 * `salaryMinor * payableDays / denominator`.
 */
final readonly class SalarySegment
{
    public function __construct(
        public string $segmentKey,
        public int $compensationPlanId,
        public string $compensationPlanUlid,
        public int $salaryMinor,
        public string $currency,
        public int $payableDays,
        public int $denominator,
        public CarbonImmutable $payableStart,
        public CarbonImmutable $payableEnd,
    ) {}

    /** Exact rational numerator (over the shared `denominator`): salary × payable days. */
    public function exactNumerator(): int
    {
        return $this->salaryMinor * $this->payableDays;
    }
}
