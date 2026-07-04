<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Support;

use App\Domain\FinanceOps\Contracts\PeriodLockRepository;
use App\Domain\FinanceOps\Enums\FinancialPeriodLockStatus;
use App\Domain\FinanceOps\Models\FinancialPeriodLock;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use Carbon\CarbonInterface;

/**
 * Database-backed period-lock enforcement (Plan §46; ADR-0007 Decision 2; Gate F;
 * Phase 18B). Replaces the Phase 17 always-open {@see UnlockedPeriodLockRepository}
 * with no change to {@see FinancialPeriodGuard} or any
 * call site — swapping this binding activates the `423 financial_period_locked`
 * contract everywhere.
 *
 * A business date is locked when an ACTIVE (`locked`) row for the merchant covers it,
 * whose scope is either merchant-wide (`branch_id IS NULL`) OR the mutation's branch.
 * A merchant-wide mutation ($branchId null) is blocked ONLY by a merchant-wide lock.
 * The guard always passes the mutation's OWN merchant (== the resolved tenant), so the
 * ambient MerchantScope and the explicit `merchant_id` predicate agree; the explicit
 * predicate also guarantees isolation if the guard ever runs with no resolved context.
 */
final class DatabasePeriodLockRepository implements PeriodLockRepository
{
    public function isLocked(int $merchantId, ?int $branchId, CarbonInterface $businessDate): bool
    {
        $date = $businessDate->toDateString();

        return FinancialPeriodLock::query()
            ->where('merchant_id', $merchantId)
            ->where('status', FinancialPeriodLockStatus::Locked->value)
            ->where('period_start', '<=', $date)
            ->where('period_end', '>=', $date)
            ->where(function ($query) use ($branchId): void {
                $query->whereNull('branch_id');
                if ($branchId !== null) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->exists();
    }
}
