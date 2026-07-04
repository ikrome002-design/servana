<?php

declare(strict_types=1);

namespace App\Domain\Branches\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Services\CashUpSnapshotWriter;
use App\Domain\Branches\Services\CashUpStateMachine;
use App\Domain\FinanceOps\Services\FinancialPeriodGuard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Resubmit a corrected cash-up (Plan §45; Phase 18B). Branch Manager maker action:
 * `correction_requested → submitted`. Expected totals are re-snapshotted under lock;
 * counted amounts (which the Branch Manager may have edited via the draft PUT while in
 * `correction_requested`) are preserved. Period gate → `423`; invalid source state →
 * `422 invalid_state_transition`.
 */
final class ResubmitCashUp
{
    public function __construct(
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly CashUpStateMachine $machine,
        private readonly CashUpSnapshotWriter $snapshot,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(BranchCashUp $cashUp, User $actor): BranchCashUp
    {
        $businessDate = CarbonImmutable::parse($cashUp->business_date?->toDateString() ?? CarbonImmutable::now('Africa/Nairobi')->toDateString(), 'Africa/Nairobi');
        $this->periodGuard->ensureOpen($cashUp->merchant_id, $cashUp->branch_id, $businessDate);

        return DB::transaction(function () use ($cashUp, $actor): BranchCashUp {
            /** @var BranchCashUp $locked */
            $locked = BranchCashUp::query()->whereKey($cashUp->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, CashUpStatus::Submitted);

            $this->snapshot->rebuild($locked, $this->snapshot->countsFromLines($locked));

            $locked->forceFill([
                'status' => CashUpStatus::Submitted->value,
                'submitted_by' => $actor->id,
                'submitted_at' => CarbonImmutable::now(),
                // A fresh review cycle: clear the prior reviewer verdict fields.
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_note' => null,
            ])->save();

            $this->audit->record(AuditEvent::CashUpResubmitted, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
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
