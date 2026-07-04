<?php

declare(strict_types=1);

namespace App\Domain\Branches\Services;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentRecordStatus;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Models\Refund;
use Illuminate\Support\Facades\DB;

/**
 * Server-authoritative cash-up expected total (Plan §45; Gate H; Phase 18B).
 *
 * The ONLY source of a cash-up's `expected_minor`. For a (merchant, branch,
 * Africa/Nairobi business date) it computes, per CONCRETE payment method, the sum of
 * VALIDATED payment components paid that business date minus the sum of FINALIZED
 * refunds of that method finalized that business date. Client input never sets an
 * expected amount; `counted_minor` is Branch Manager input; `variance = counted -
 * expected`. `split_payment` is never a line (a split is its concrete components).
 * pending / rejected / correction_required payments and non-finalized refunds are
 * excluded. All amounts are integer minor units.
 */
final class CashUpExpectedTotalCalculator
{
    private const TZ = 'Africa/Nairobi';

    /**
     * Expected minor units per concrete method for the branch-day. Methods with no
     * activity are omitted (the draft action treats a missing method as expected 0).
     *
     * @return array<string, int> method value => expected minor units (may be negative if refunds exceed validated)
     */
    public function forBranchDay(int $merchantId, int $branchId, string $businessDate): array
    {
        $expected = [];

        // Validated payment components paid on the branch business date, by method.
        $validated = PaymentRecord::query()
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)
            ->where('status', PaymentRecordStatus::Validated->value)
            ->whereRaw('(paid_at AT TIME ZONE ?)::date = ?', [self::TZ, $businessDate])
            ->groupBy('method')
            ->select('method', DB::raw('SUM(validated_amount_minor) AS total'))
            ->pluck('total', 'method');

        foreach ($validated as $method => $total) {
            $expected[(string) $method] = (int) $total;
        }

        // Finalized refunds finalized on the branch business date reduce the drawer.
        $refunded = Refund::query()
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)
            ->where('status', RefundStatus::Finalized->value)
            ->whereRaw('(finalized_at AT TIME ZONE ?)::date = ?', [self::TZ, $businessDate])
            ->groupBy('method')
            ->select('method', DB::raw('SUM(amount_minor) AS total'))
            ->pluck('total', 'method');

        foreach ($refunded as $method => $total) {
            $key = (string) $method;
            $expected[$key] = ($expected[$key] ?? 0) - (int) $total;
        }

        // Guard: split_payment must never surface as an expected line (Gate B).
        unset($expected[PaymentMethod::SplitPayment->value]);

        return $expected;
    }
}
