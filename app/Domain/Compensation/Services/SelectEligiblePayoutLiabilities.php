<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use Illuminate\Support\Collection;

/**
 * Selects the Phase 20G ledger facts eligible to be snapshotted into a payout run (Plan §62; §H4).
 * Facts are SNAPSHOTTED, never recomputed from current plans/rules. Eligibility is bounded by the
 * run's merchant / branch / currency / period and `payout_item_id IS NULL` (unlinked). Reversal rows
 * are excluded: an unpaid ledger reversal both writes a negative `entry_type='reversal'` row AND
 * flips its original to `status='reversed'`, so excluding both nets a fully-reversed earning to zero
 * (verified against ReverseCommissionEntry/ReverseSalaryAccrual). Already-paid reversals live in
 * `compensation_adjustments` (signed) and flow through the adjustments bucket.
 *
 * Business-date bounds use Africa/Nairobi (commission `earned_at`, salary `pay_period_end`,
 * adjustment `created_at`). Pass `lock: true` inside the submit transaction to take a FOR UPDATE lock
 * so the claim is race-free.
 *
 * @return array{
 *     salary: Collection<int, SalaryLedgerEntry>,
 *     commission: Collection<int, CommissionLedgerEntry>,
 *     adjustment: Collection<int, CompensationAdjustment>,
 * }
 */
final class SelectEligiblePayoutLiabilities
{
    /** @return array{salary: Collection<int, SalaryLedgerEntry>, commission: Collection<int, CommissionLedgerEntry>, adjustment: Collection<int, CompensationAdjustment>} */
    public function forRun(PersonnelPayoutRun $run, bool $lock = false): array
    {
        return [
            'salary' => $this->salary($run, $lock),
            'commission' => $this->commission($run, $lock),
            'adjustment' => $this->adjustment($run, $lock),
        ];
    }

    /** @return Collection<int, SalaryLedgerEntry> */
    private function salary(PersonnelPayoutRun $run, bool $lock): Collection
    {
        $query = SalaryLedgerEntry::query()
            ->where('merchant_id', $run->merchant_id)
            ->where('branch_id', $run->branch_id)
            ->where('currency', $run->currency)
            ->whereIn('entry_type', ['accrual', 'adjustment'])
            ->where('status', 'pending')
            ->whereNull('payout_item_id')
            ->whereBetween('pay_period_end', [$run->period_start->toDateString(), $run->period_end->toDateString()])
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** @return Collection<int, CommissionLedgerEntry> */
    private function commission(PersonnelPayoutRun $run, bool $lock): Collection
    {
        $query = CommissionLedgerEntry::query()
            ->where('merchant_id', $run->merchant_id)
            ->where('branch_id', $run->branch_id)
            ->where('currency', $run->currency)
            ->whereIn('entry_type', ['earned', 'adjustment'])
            ->where('status', 'earned')
            ->whereNull('payout_item_id')
            ->whereRaw("(earned_at AT TIME ZONE 'Africa/Nairobi')::date BETWEEN ? AND ?", [
                $run->period_start->toDateString(),
                $run->period_end->toDateString(),
            ])
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** @return Collection<int, CompensationAdjustment> */
    private function adjustment(PersonnelPayoutRun $run, bool $lock): Collection
    {
        $query = CompensationAdjustment::query()
            ->where('merchant_id', $run->merchant_id)
            ->where('branch_id', $run->branch_id)
            ->where('currency', $run->currency)
            ->whereNull('payout_item_id')
            ->whereRaw("(created_at AT TIME ZONE 'Africa/Nairobi')::date <= ?", [$run->period_end->toDateString()])
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }
}
