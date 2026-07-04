<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Services\CashUpStateMachine;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lock an approved cash-up (Plan §45; Phase 18B). Finance action: `approved → locked`
 * (terminal). Once locked the branch-day may close. The snapshot is not overwritten.
 * Period gate → `423`; invalid source state → `422 invalid_state_transition`.
 */
final class LockApprovedCashUp
{
    public function __construct(
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly CashUpStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(BranchCashUp $cashUp, User $actor): BranchCashUp
    {
        $businessDate = CarbonImmutable::parse($cashUp->business_date?->toDateString() ?? CarbonImmutable::now('Africa/Nairobi')->toDateString(), 'Africa/Nairobi');
        $this->periodGuard->ensureOpen($cashUp->merchant_id, $cashUp->branch_id, $businessDate);

        return DB::transaction(function () use ($cashUp, $actor): BranchCashUp {
            /** @var BranchCashUp $locked */
            $locked = BranchCashUp::query()->whereKey($cashUp->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, CashUpStatus::Locked);

            $locked->forceFill(['status' => CashUpStatus::Locked->value])->save();

            $this->audit->record(AuditEvent::CashUpLocked, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
                'cash_up_id' => $locked->ulid,
                'business_date' => $locked->business_date?->toDateString(),
                'variance_minor' => $locked->variance_minor,
            ]);

            return $locked->load('lines');
        });
    }
}
