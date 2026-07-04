<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\FinanceOps\Enums\FinancialPeriodLockStatus;
use App\Domain\FinanceOps\Exceptions\FinancialPeriodLockException;
use App\Domain\FinanceOps\Models\FinancialPeriodLock;
use App\Domain\FinanceOps\Services\FinancialPeriodLockStateMachine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Execute a controlled period reopen (Plan §46; ADR-0007 Decision 3; Phase 18B).
 * Finance-owned (`period_lock.reopen`), fresh MFA enforced on the route. Transitions
 * `locked → reopened`, recording the executor. A reopen must have been requested
 * (mandatory reason captured then). For an `exception_required` lock, execution is
 * refused unless a DISTINCT Merchant Administrator approval is present
 * (`period_reopen_approval_required`). Emits `financial_period.reopened`.
 */
final class ExecutePeriodReopen
{
    public function __construct(
        private readonly FinancialPeriodLockStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(FinancialPeriodLock $lock, User $executor): FinancialPeriodLock
    {
        return DB::transaction(function () use ($lock, $executor): FinancialPeriodLock {
            /** @var FinancialPeriodLock $locked */
            $locked = FinancialPeriodLock::query()->whereKey($lock->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, FinancialPeriodLockStatus::Reopened);

            if ($locked->reopen_requested_by === null) {
                throw FinancialPeriodLockException::reopenNotRequested();
            }
            if ($locked->exception_required
                && ($locked->reopen_approved_by === null || $locked->reopen_approved_by === $locked->reopen_requested_by)) {
                throw FinancialPeriodLockException::approvalRequired();
            }

            $locked->forceFill([
                'status' => FinancialPeriodLockStatus::Reopened->value,
                'reopened_by' => $executor->id,
                'reopened_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(AuditEvent::FinancialPeriodReopened, $executor, $locked->merchant_id, $locked->branch_id, $locked, [
                'period_lock_id' => $locked->ulid,
                'exception_required' => $locked->exception_required,
                'branch_scope' => $locked->branch_id === null ? 'merchant' : 'branch',
            ]);

            return $locked;
        });
    }
}
