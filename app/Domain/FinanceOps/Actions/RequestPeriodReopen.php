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
use Illuminate\Support\Facades\DB;

/**
 * Request a controlled reopen of a locked financial period (Plan §46; ADR-0007
 * Decision 3; Phase 18B). Finance-owned (`period_lock.reopen`). Records the requester,
 * reason, and timestamp; the lock STAYS `locked` (a request, not an execution). For a
 * routine (non-exception) lock the same Finance user proceeds to execute; for an
 * `exception_required` lock a distinct Merchant Administrator must approve first. A
 * mandatory reason is required. Emits `financial_period.reopen_requested`.
 */
final class RequestPeriodReopen
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(FinancialPeriodLock $lock, User $requester, string $reason): FinancialPeriodLock
    {
        if (trim($reason) === '') {
            throw FinancialPeriodLockException::reasonRequired();
        }

        return DB::transaction(function () use ($lock, $requester, $reason): FinancialPeriodLock {
            /** @var FinancialPeriodLock $locked */
            $locked = FinancialPeriodLock::query()->whereKey($lock->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== FinancialPeriodLockStatus::Locked) {
                throw FinancialPeriodLockException::invalidTransition($locked->status, FinancialPeriodLockStatus::Reopened);
            }

            $locked->forceFill([
                'reopen_requested_by' => $requester->id,
                'reopen_requested_at' => CarbonImmutable::now(),
                'reopen_reason' => $reason,
                // A fresh request clears any stale approval from a prior cycle.
                'reopen_approved_by' => null,
                'reopen_approved_at' => null,
            ])->save();

            $this->audit->record(AuditEvent::FinancialPeriodReopenRequested, $requester, $locked->merchant_id, $locked->branch_id, $locked, [
                'period_lock_id' => $locked->ulid,
                'exception_required' => $locked->exception_required,
                'reason' => $reason,
            ]);

            return $locked;
        });
    }
}
