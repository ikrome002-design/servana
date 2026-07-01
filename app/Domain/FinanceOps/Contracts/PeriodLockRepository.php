<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Contracts;

use App\Domain\FinanceOps\Support\UnlockedPeriodLockRepository;
use Carbon\CarbonInterface;

/**
 * Period-lock persistence seam (Plan §46; Gate C, Phase 17).
 *
 * The `financial_period_locks` table and its management workflow are owned by
 * Phase 18B. Phase 17 implements only the invoice-side **enforcement contract**:
 * every financial mutation asks this repository whether the affected period is
 * locked, and is denied with `423 financial_period_locked` if it is.
 *
 * Phase 17 binds {@see UnlockedPeriodLockRepository}
 * (always open — the table does not exist yet). Phase 18B swaps in a DB-backed
 * implementation with NO change to the invoice actions.
 */
interface PeriodLockRepository
{
    /**
     * Whether the given merchant (and branch, when branch-scoped) has a financial
     * period lock covering $businessDate.
     */
    public function isLocked(int $merchantId, ?int $branchId, CarbonInterface $businessDate): bool;
}
