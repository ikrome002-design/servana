<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\ValueObjects\SalarySegment;
use InvalidArgumentException;

/**
 * Actual/Actual calendar-day salary proration (Plan §60; G8 product-owner decision). Pure, integer
 * arithmetic only — never floating-point money.
 *
 * For all payable segments of ONE pay period (uniform denominator D — actual days in the Nairobi
 * month, or 7 for a weekly ISO week):
 *   exact_i    = salary_i * payableDays_i / D
 *   period sum = (Σ salary_i * payableDays_i) / D
 *   total      = round_half_up(period sum)              // round the PERIOD total ONCE (ADR-005)
 *   floor_i    = ⌊(salary_i * payableDays_i) / D⌋
 *   residual   = total − Σ floor_i
 *   rank segments by DESCENDING remainder ((salary_i*payableDays_i) mod D), tie-break ASCENDING
 *   payable-start date → compensation-plan ULID → segment key; add one minor unit to the top
 *   `residual` segments.
 *
 * Guarantees Σ segment amounts == the rounded period total exactly; a full-period single-plan
 * segment (payableDays == D) accrues exactly one full period salary regardless of month length.
 */
final class SalaryProrationCalculator
{
    /**
     * @param  list<SalarySegment>  $segments  every payable segment of ONE pay period (same denominator + currency)
     * @return array<string,int> segmentKey => accrued amount_minor (integer, summing to the rounded period total)
     */
    public function allocate(array $segments): array
    {
        if ($segments === []) {
            return [];
        }

        $denominator = $segments[0]->denominator;
        $currency = $segments[0]->currency;

        foreach ($segments as $segment) {
            if ($segment->denominator !== $denominator) {
                throw new InvalidArgumentException('Salary segments in one pay period must share a denominator.');
            }
            if ($segment->currency !== $currency) {
                throw new InvalidArgumentException('Salary segments in one pay period must share a currency.');
            }
            if ($segment->payableDays < 0 || $segment->payableDays > $denominator) {
                throw new InvalidArgumentException('Salary segment payable days must be within the pay period.');
            }
        }
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Salary pay-period denominator must be positive.');
        }

        $exactNumeratorTotal = 0;
        $floorByKey = [];
        $remainderByKey = [];
        $flooredTotal = 0;

        foreach ($segments as $segment) {
            $numerator = $segment->exactNumerator();
            $exactNumeratorTotal += $numerator;
            $floor = intdiv($numerator, $denominator);
            $floorByKey[$segment->segmentKey] = $floor;
            $remainderByKey[$segment->segmentKey] = $numerator % $denominator;
            $flooredTotal += $floor;
        }

        // Round the PERIOD total once (round-half-up of exactNumeratorTotal / denominator).
        $roundedTotal = intdiv(2 * $exactNumeratorTotal + $denominator, 2 * $denominator);
        $residual = $roundedTotal - $flooredTotal;

        // Deterministic tie-break map: key => [remainder, startTimestamp, planUlid, key].
        $order = [];
        foreach ($segments as $segment) {
            $order[$segment->segmentKey] = [
                $remainderByKey[$segment->segmentKey],
                $segment->payableStart->getTimestamp(),
                $segment->compensationPlanUlid,
                $segment->segmentKey,
            ];
        }
        uksort($order, static function (string $a, string $b) use ($order): int {
            // Descending remainder, then ascending start date, plan ULID, segment key.
            return ($order[$b][0] <=> $order[$a][0])
                ?: ($order[$a][1] <=> $order[$b][1])
                ?: strcmp($order[$a][2], $order[$b][2])
                ?: strcmp($order[$a][3], $order[$b][3]);
        });

        $amounts = $floorByKey;
        foreach (array_keys($order) as $key) {
            if ($residual <= 0) {
                break;
            }
            $amounts[$key]++;
            $residual--;
        }

        return $amounts;
    }
}
