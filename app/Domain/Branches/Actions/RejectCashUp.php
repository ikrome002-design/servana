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
 * Reject a submitted cash-up (Plan §45; Phase 18B). Finance checker action:
 * `submitted → rejected` (terminal for this cycle). Requires a mandatory reason;
 * maker/checker separation (rejecter != submitter → `403 maker_is_checker`). The
 * submitted snapshot is not overwritten. Period gate → `423`; invalid source state →
 * `422 invalid_state_transition`.
 */
final class RejectCashUp
{
    public function __construct(
        private readonly FinancialPeriodGuard $periodGuard,
        private readonly CashUpStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(BranchCashUp $cashUp, User $checker, string $reason): BranchCashUp
    {
        if (trim($reason) === '') {
            throw CashUpException::reasonRequired();
        }

        $businessDate = CarbonImmutable::parse($cashUp->business_date?->toDateString() ?? CarbonImmutable::now('Africa/Nairobi')->toDateString(), 'Africa/Nairobi');
        $this->periodGuard->ensureOpen($cashUp->merchant_id, $cashUp->branch_id, $businessDate);

        return DB::transaction(function () use ($cashUp, $checker, $reason): BranchCashUp {
            /** @var BranchCashUp $locked */
            $locked = BranchCashUp::query()->whereKey($cashUp->id)->lockForUpdate()->firstOrFail();

            if ($locked->submitted_by === $checker->id) {
                throw CashUpException::makerIsChecker();
            }

            $this->machine->ensure($locked->status, CashUpStatus::Rejected);

            $locked->forceFill([
                'status' => CashUpStatus::Rejected->value,
                'reviewed_by' => $checker->id,
                'reviewed_at' => CarbonImmutable::now(),
                'review_note' => $reason,
            ])->save();

            $this->audit->record(AuditEvent::CashUpRejected, $checker, $locked->merchant_id, $locked->branch_id, $locked, [
                'cash_up_id' => $locked->ulid,
                'business_date' => $locked->business_date?->toDateString(),
                'variance_minor' => $locked->variance_minor,
                'reason' => $reason,
            ]);

            return $locked->load('lines');
        });
    }
}
