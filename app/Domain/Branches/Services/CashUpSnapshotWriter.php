<?php

declare(strict_types=1);

namespace App\Domain\Branches\Services;

use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\CashUpLine;

/**
 * Rebuilds a cash-up's per-method {@see CashUpLine} rows and header totals from the
 * SERVER-derived expected amounts ({@see CashUpExpectedTotalCalculator}) and the
 * Branch Manager's entered counts (Plan §45; Gate H; Phase 18B). The single writer
 * used by both the draft PUT and the submit snapshot, so expected is never client
 * supplied and header totals always equal Σ line totals. All amounts are integer
 * minor units.
 */
final class CashUpSnapshotWriter
{
    public function __construct(private readonly CashUpExpectedTotalCalculator $calculator) {}

    /**
     * @param  array<string, int>  $counts  concrete method value => counted minor units
     */
    public function rebuild(BranchCashUp $cashUp, array $counts): void
    {
        $businessDate = $cashUp->business_date?->toDateString() ?? '';
        $expected = $this->calculator->forBranchDay($cashUp->merchant_id, $cashUp->branch_id, $businessDate);

        $methods = array_values(array_unique(array_merge(array_keys($expected), array_keys($counts))));
        $cashUp->lines()->delete();

        $expectedTotal = 0;
        $countedTotal = 0;
        foreach ($methods as $method) {
            $exp = (int) ($expected[$method] ?? 0);
            $cnt = (int) ($counts[$method] ?? 0);
            if ($exp === 0 && $cnt === 0) {
                continue;
            }
            CashUpLine::query()->create([
                'merchant_id' => $cashUp->merchant_id,
                'branch_id' => $cashUp->branch_id,
                'cash_up_id' => $cashUp->id,
                'method' => $method,
                'expected_minor' => $exp,
                'counted_minor' => $cnt,
                'variance_minor' => $cnt - $exp,
            ]);
            $expectedTotal += $exp;
            $countedTotal += $cnt;
        }

        $variance = $countedTotal - $expectedTotal;
        $cashUp->forceFill([
            'expected_minor' => $expectedTotal,
            'counted_minor' => $countedTotal,
            'variance_minor' => $variance,
            // Keep the legacy seam columns coherent (BranchClosureGuard reads them).
            'expected_total' => $expectedTotal,
            'cash_counted' => $countedTotal,
            'discrepancy_amount' => $variance,
        ])->save();

        $cashUp->load('lines');
    }

    /**
     * The counted amounts currently stored on a cash-up's lines, keyed by method.
     *
     * @return array<string, int>
     */
    public function countsFromLines(BranchCashUp $cashUp): array
    {
        $counts = [];
        foreach ($cashUp->lines()->get() as $line) {
            $counts[$line->method->value] = $line->counted_minor;
        }

        return $counts;
    }
}
