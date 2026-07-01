<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Support;

use App\Domain\FinanceOps\Contracts\PeriodLockRepository;
use Carbon\CarbonInterface;

/**
 * Phase 17 default {@see PeriodLockRepository}: no period is ever locked because
 * the `financial_period_locks` table and lock-management workflow do not exist
 * until Phase 18B (Gate C). The guard is still wired into every financial
 * mutation, so Phase 18B can replace this binding with a DB-backed repository and
 * the `423 financial_period_locked` contract activates with no action change.
 */
final class UnlockedPeriodLockRepository implements PeriodLockRepository
{
    public function isLocked(int $merchantId, ?int $branchId, CarbonInterface $businessDate): bool
    {
        return false;
    }
}
