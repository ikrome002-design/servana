<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Enums\FinancialPeriodLockStatus;
use App\Domain\FinanceOps\Exceptions\FinancialPeriodLockException;
use App\Domain\FinanceOps\Models\FinancialPeriodLock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Create a financial period lock (Plan §46; ADR-0007 Decision 2/3; Gate F; Phase 18B).
 * Finance-owned (`period_lock.create`). The lock is merchant-wide (`branchId` null) or
 * branch-specific. A lock may not overlap an existing active lock for the same scope
 * (pre-checked here and ultimately enforced by the `financial_period_locks_no_overlap`
 * EXCLUDE constraint) → `422 overlapping_period_lock`. `exception_required` is sourced
 * from existing merchant configuration (Phase 18B minimal — no new policy engine).
 */
final class CreateFinancialPeriodLock
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(
        int $merchantId,
        ?int $branchId,
        string $periodStart,
        string $periodEnd,
        User $actor,
        bool $exceptionRequired = false,
    ): FinancialPeriodLock {
        if ($periodStart > $periodEnd) {
            throw FinancialPeriodLockException::invalidRange();
        }

        return DB::transaction(function () use ($merchantId, $branchId, $periodStart, $periodEnd, $actor, $exceptionRequired): FinancialPeriodLock {
            $this->assertNoOverlap($merchantId, $branchId, $periodStart, $periodEnd);

            try {
                $lock = FinancialPeriodLock::query()->create([
                    'merchant_id' => $merchantId,
                    'branch_id' => $branchId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'status' => FinancialPeriodLockStatus::Locked->value,
                    'exception_required' => $exceptionRequired,
                    'locked_by' => $actor->id,
                    'locked_at' => CarbonImmutable::now(),
                ]);
            } catch (QueryException $e) {
                // The EXCLUDE constraint is the ultimate overlap guard (race with the pre-check).
                if (str_contains($e->getMessage(), 'financial_period_locks_no_overlap')) {
                    throw FinancialPeriodLockException::overlapping();
                }
                throw $e;
            }

            $this->audit->record(AuditEvent::FinancialPeriodLocked, $actor, $merchantId, $branchId, $lock, [
                'period_lock_id' => $lock->ulid,
                'branch_scope' => $branchId === null ? 'merchant' : 'branch',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'exception_required' => $exceptionRequired,
            ]);

            return $lock;
        });
    }

    private function assertNoOverlap(int $merchantId, ?int $branchId, string $periodStart, string $periodEnd): void
    {
        $overlaps = FinancialPeriodLock::query()
            ->where('merchant_id', $merchantId)
            ->where('status', FinancialPeriodLockStatus::Locked->value)
            ->whereRaw('COALESCE(branch_id, 0) = ?', [$branchId ?? 0])
            ->where('period_start', '<=', $periodEnd)
            ->where('period_end', '>=', $periodStart)
            ->exists();

        if ($overlaps) {
            throw FinancialPeriodLockException::overlapping();
        }
    }
}
