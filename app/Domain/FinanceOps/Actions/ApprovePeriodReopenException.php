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
 * Approve an EXCEPTIONAL period reopen (Plan §46; ADR-0007 Decision 3; Gate F; Phase
 * 18B). Merchant-Administrator-owned (`merchant.period_reopen.approve_exception`). Only
 * valid on an `exception_required` lock that has a pending reopen REQUEST, and the
 * approver must be DISTINCT from the requester (`403 maker_is_checker`; also enforced by
 * the `financial_period_locks_reopen_maker_checker_check` DB CHECK). The lock stays
 * `locked` until Finance executes. Emits `financial_period.reopen_approved`.
 */
final class ApprovePeriodReopenException
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(FinancialPeriodLock $lock, User $approver): FinancialPeriodLock
    {
        return DB::transaction(function () use ($lock, $approver): FinancialPeriodLock {
            /** @var FinancialPeriodLock $locked */
            $locked = FinancialPeriodLock::query()->whereKey($lock->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== FinancialPeriodLockStatus::Locked) {
                throw FinancialPeriodLockException::invalidTransition($locked->status, FinancialPeriodLockStatus::Reopened);
            }
            if (! $locked->exception_required || $locked->reopen_requested_by === null) {
                throw FinancialPeriodLockException::reopenNotRequested();
            }
            if ($locked->reopen_requested_by === $approver->id) {
                throw FinancialPeriodLockException::makerIsChecker();
            }

            $locked->forceFill([
                'reopen_approved_by' => $approver->id,
                'reopen_approved_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(AuditEvent::FinancialPeriodReopenApproved, $approver, $locked->merchant_id, $locked->branch_id, $locked, [
                'period_lock_id' => $locked->ulid,
                'exception_required' => $locked->exception_required,
            ]);

            return $locked;
        });
    }
}
