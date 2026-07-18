<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Deterministic ADR-005 integer money helpers — round-half-up and largest-remainder residual
 * allocation. Pure, side-effect free, domain-neutral. Phase 20E's
 * `AllocatePlatformFeeByLargestRemainder` implements the same algorithm for the fee ledger; this
 * neutral helper is used by Phase 20G (commission allocation across invoice items, salary residual
 * across pay-period segments) without coupling Compensation to the Billing fee service.
 *
 * Integer minor units only — never floating-point money.
 */
final class LargestRemainderAllocator
{
    /**
     * Round-half-up of `basisMinor * basisPoints / 10000` to integer minor units (ADR-005).
     * `basisMinor` must be non-negative (commission/fee bases are non-negative).
     */
    public static function roundHalfUp(int $basisMinor, int $basisPoints): int
    {
        return intdiv($basisMinor * $basisPoints + 5000, 10000);
    }

    /**
     * Allocate a non-negative total across keyed integer weights so the shares sum EXACTLY to the
     * total. Residual minor units go to the largest fractional remainders; ties break by ascending
     * key (immutable ULID / deterministic segment key), so the result is replay-stable and
     * independent of input/DB ordering.
     *
     * @param  array<string,int>  $weightsByKey  key → non-negative weight
     * @return array<string,int> the same keys → integer shares summing to $totalMinor
     */
    public static function allocate(int $totalMinor, array $weightsByKey): array
    {
        $keys = array_keys($weightsByKey);
        if ($keys === []) {
            return [];
        }

        $totalWeight = array_sum($weightsByKey);

        // Degenerate: no positive weight. Distribute one minor unit at a time in ascending key order.
        if ($totalWeight <= 0) {
            $shares = array_fill_keys($keys, 0);
            sort($keys);
            for ($i = 0; $i < $totalMinor; $i++) {
                $shares[$keys[$i % count($keys)]]++;
            }
            ksort($shares);

            return $shares;
        }

        $shares = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weightsByKey as $key => $weight) {
            $exact = $totalMinor * $weight;
            $floor = intdiv($exact, $totalWeight);
            $shares[$key] = $floor;
            $remainders[$key] = $exact % $totalWeight;
            $allocated += $floor;
        }

        $residual = $totalMinor - $allocated;

        // Rank by descending remainder, then ascending key for a stable tie-break.
        uksort($remainders, static fn (string $a, string $b): int => ($remainders[$b] <=> $remainders[$a]) ?: strcmp($a, $b));

        foreach (array_keys($remainders) as $key) {
            if ($residual <= 0) {
                break;
            }
            $shares[$key]++;
            $residual--;
        }

        return $shares;
    }
}
