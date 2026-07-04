<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Branches\Exceptions\CashUpException;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Services\CashUpStateMachine;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Approve a submitted cash-up (Plan §45; Phase 18B). Finance checker action:
 * `submitted → approved`. Maker/checker separation — the approver may NOT be the
 * Branch Manager who submitted it (`403 maker_is_checker`). The submitted counted/
 * expected snapshot is NOT overwritten. Period gate → `423`; invalid source state →
 * `422 invalid_state_transition`.
 */
final class ApproveCashUp
{
    public function __construct(
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly CashUpStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(BranchCashUp $cashUp, User $checker): BranchCashUp
    {
        $businessDate = CarbonImmutable::parse($cashUp->business_date?->toDateString() ?? CarbonImmutable::now('Africa/Nairobi')->toDateString(), 'Africa/Nairobi');
        $this->periodGuard->ensureOpen($cashUp->merchant_id, $cashUp->branch_id, $businessDate);

        return DB::transaction(function () use ($cashUp, $checker): BranchCashUp {
            /** @var BranchCashUp $locked */
            $locked = BranchCashUp::query()->whereKey($cashUp->id)->lockForUpdate()->firstOrFail();

            if ($locked->submitted_by === $checker->id) {
                throw CashUpException::makerIsChecker();
            }

            $this->machine->ensure($locked->status, CashUpStatus::Approved);

            $locked->forceFill([
                'status' => CashUpStatus::Approved->value,
                'approved_by' => $checker->id,
                'approved_at' => CarbonImmutable::now(),
                'reviewed_by' => $checker->id,
                'reviewed_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(AuditEvent::CashUpApproved, $checker, $locked->merchant_id, $locked->branch_id, $locked, [
                'cash_up_id' => $locked->ulid,
                'business_date' => $locked->business_date?->toDateString(),
                'expected_minor' => $locked->expected_minor,
                'counted_minor' => $locked->counted_minor,
                'variance_minor' => $locked->variance_minor,
            ]);

            return $locked->load('lines');
        });
    }
}
