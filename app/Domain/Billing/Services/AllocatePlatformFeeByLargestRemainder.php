<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\ValueObjects\AllocatedPlatformFeeItem;
use App\Domain\Billing\ValueObjects\CalculatedPlatformFee;

/**
 * Deterministic largest-remainder allocation of an invoice-level platform fee across its items
 * (Plan §51; ADR-005 §Gate E5; Phase 20E). Integer minor units.
 *
 * Core algorithm ({@see allocate()}): for a total T and per-key integer weights w_i (Σw = W):
 *   1. exact_i    = T * w_i
 *   2. floor_i    = ⌊exact_i / W⌋
 *   3. residual   = T - Σ floor_i
 *   4. rank keys by descending remainder (exact_i mod W), tie-break by ascending key (immutable
 *      invoice-item ULID)
 *   5. add one minor unit to the top `residual` keys
 * Guarantees Σ share_i == T and is stable/replay-deterministic (independent of input/DB ordering).
 *
 * {@see allocateFee()} applies this twice — once to the gross fee (weighted by item basis) and once to
 * the client-shifted amount (weighted by the resulting item gross) — so that both
 * Σ item_gross == invoice_gross AND Σ item_client_shifted == invoice_client_shifted hold exactly, and
 * each item's absorbed = gross - client_shifted.
 */
final class AllocatePlatformFeeByLargestRemainder
{
    /**
     * @param  array<string,int>  $weightsByKey  keyed by immutable item ULID → non-negative weight
     * @return array<string,int> keyed by the same keys → integer shares summing exactly to $totalMinor
     */
    public function allocate(int $totalMinor, array $weightsByKey): array
    {
        $keys = array_keys($weightsByKey);
        if ($keys === []) {
            return [];
        }

        $totalWeight = array_sum($weightsByKey);

        // Degenerate: no positive weight (e.g. all zero-value items). Distribute the total one minor unit
        // at a time in ascending key order so the sum still reconciles.
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

        // Rank by descending remainder, then ascending key (immutable ULID) for a stable tie-break.
        uksort($remainders, function (string $a, string $b) use ($remainders): int {
            return $remainders[$b] <=> $remainders[$a] ?: strcmp($a, $b);
        });

        foreach (array_keys($remainders) as $key) {
            if ($residual <= 0) {
                break;
            }
            $shares[$key]++;
            $residual--;
        }

        // Deterministic output ordering (ascending immutable key), independent of input order.
        ksort($shares);

        return $shares;
    }

    /**
     * @param  array<string,int>  $itemBasisByKey  immutable item ULID → item basis (minor units)
     * @return list<AllocatedPlatformFeeItem>
     */
    public function allocateFee(CalculatedPlatformFee $invoiceFee, array $itemBasisByKey): array
    {
        $grossByKey = $this->allocate($invoiceFee->grossMinor, $itemBasisByKey);
        $shiftedByKey = $this->allocate($invoiceFee->clientShiftedMinor, $grossByKey);

        $items = [];
        foreach ($grossByKey as $key => $gross) {
            $shifted = $shiftedByKey[$key];
            $items[] = new AllocatedPlatformFeeItem(
                key: (string) $key,
                grossMinor: $gross,
                clientShiftedMinor: $shifted,
                absorbedMinor: $gross - $shifted,
            );
        }

        return $items;
    }
}
