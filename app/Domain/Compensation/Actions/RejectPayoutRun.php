<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\PayoutItemStatus;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Compensation\Services\PayoutRunStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Finance rejects a pre-paid payout run (Plan §62; §H5). Moves `submitted | finance_verified |
 * pending_merchant_admin_approval → rejected` with a mandatory reason, and RELEASES every claimed
 * ledger row (clears `payout_item_id`, status untouched) so the liability returns to the eligible
 * pool for a new draft. Corrections are via a new draft/run — never a silent line edit. Single
 * transaction with a row lock; audits `payout_run.rejected` (warn).
 */
final class RejectPayoutRun
{
    public function __construct(
        private readonly PayoutRunStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PersonnelPayoutRun $run, User $actor, string $reason): PersonnelPayoutRun
    {
        return DB::transaction(function () use ($run, $actor, $reason): PersonnelPayoutRun {
            $locked = PersonnelPayoutRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, PayoutRunStatus::Rejected);

            /** @var list<int> $itemIds */
            $itemIds = PersonnelPayoutItem::query()->where('payout_run_id', $locked->id)->pluck('id')->all();
            $this->release($itemIds);

            $locked->status = PayoutRunStatus::Rejected;
            $locked->rejected_by = $actor->id;
            $locked->rejection_reason = $reason;
            $locked->save();

            PersonnelPayoutItem::query()
                ->where('payout_run_id', $locked->id)
                ->update(['status' => PayoutItemStatus::Rejected->value]);

            $this->audit->record(
                AuditEvent::PayoutRunRejected,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'payout_run_id' => $locked->ulid,
                    'reason' => $reason,
                ],
            );

            return $locked->refresh();
        });
    }

    /**
     * Release claimed ledger rows: clear payout_item_id (status untouched).
     *
     * @param  list<int>  $itemIds
     */
    private function release(array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }

        foreach ([SalaryLedgerEntry::class, CommissionLedgerEntry::class, CompensationAdjustment::class] as $model) {
            $model::query()->whereIn('payout_item_id', $itemIds)->update(['payout_item_id' => null]);
        }
    }
}
