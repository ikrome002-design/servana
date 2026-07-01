<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Services;

use App\Domain\FinanceOps\Contracts\PeriodLockRepository;
use App\Domain\FinanceOps\Exceptions\FinancialPeriodLockedException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Invoice-side period-lock enforcement (Plan §46; Gate C, Phase 17).
 *
 * Single guard every Phase 17 financial mutation (finalize/void/adjust) calls
 * before writing. It consults the injected {@see PeriodLockRepository}; if the
 * affected period is locked it throws {@see FinancialPeriodLockedException}
 * (`423 financial_period_locked`). Phase 17 binds an always-open repository, so
 * the guard is a no-op in practice today but is fully wired and tested; Phase 18B
 * supplies the DB-backed persistence with no change here. The guard is NEVER
 * bypassed merely because Phase 18B has not yet shipped lock management.
 */
final readonly class FinancialPeriodGuard
{
    public function __construct(private PeriodLockRepository $locks) {}

    /**
     * @throws FinancialPeriodLockedException when the period is locked.
     */
    public function ensureOpen(int $merchantId, ?int $branchId, ?CarbonInterface $businessDate = null): void
    {
        $date = $businessDate ?? CarbonImmutable::now('Africa/Nairobi');

        if ($this->locks->isLocked($merchantId, $branchId, $date)) {
            throw FinancialPeriodLockedException::forPeriod();
        }
    }
}
