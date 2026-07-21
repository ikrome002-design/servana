<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\PayoutItemStatus;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Compensation\Services\PayoutRunStateMachine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finance marks an approved payout run PAID after an EXTERNAL payment (Plan §62; §25.5; §H8).
 * **Servana moves no money** — this records that an external settlement already happened; there is no
 * provider/Wallet call and no dependency on Gate W. In one transaction with the run + items locked
 * FOR UPDATE: advance each linked ledger row FORWARD `earned/pending → included_in_payout → paid`
 * (the only place status advances), set the run + items paid, store the encrypted external reference +
 * paid date. Idempotency is enforced at the API boundary (Idempotency-Key). Audits
 * `payout_run.marked_paid` (critical).
 */
final class MarkPayoutRunPaid
{
    public function __construct(
        private readonly PayoutRunStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        PersonnelPayoutRun $run,
        User $actor,
        string $externalPaymentReference,
        string $paidDate,
    ): PersonnelPayoutRun {
        return DB::transaction(function () use ($run, $actor, $externalPaymentReference, $paidDate): PersonnelPayoutRun {
            $locked = PersonnelPayoutRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->machine->ensure($locked->status, PayoutRunStatus::Paid);

            /** @var list<int> $itemIds */
            $itemIds = PersonnelPayoutItem::query()
                ->where('payout_run_id', $locked->id)
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            $this->settleLedgers($itemIds);

            $locked->status = PayoutRunStatus::Paid;
            $locked->paid_by = $actor->id;
            $locked->external_payment_reference_encrypted = $externalPaymentReference;
            $locked->paid_at = Carbon::parse($paidDate);
            $locked->save();

            PersonnelPayoutItem::query()
                ->where('payout_run_id', $locked->id)
                ->update(['status' => PayoutItemStatus::Paid->value]);

            $this->audit->record(
                AuditEvent::PayoutRunMarkedPaid,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'payout_run_id' => $locked->ulid,
                    'gross_total_minor' => $locked->gross_total_minor,
                    'paid_at' => $locked->paid_at->toDateString(),
                    // The external reference itself is NEVER placed in the audit payload.
                ],
            );

            return $locked->refresh();
        });
    }

    /**
     * Advance linked salary/commission ledgers FORWARD in two steps (earned/pending →
     * included_in_payout → paid), honouring the forward-only 20G enums. Adjustments carry no status.
     *
     * @param  list<int>  $itemIds
     */
    private function settleLedgers(array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }

        SalaryLedgerEntry::query()->whereIn('payout_item_id', $itemIds)->where('status', 'pending')
            ->update(['status' => 'included_in_payout']);
        SalaryLedgerEntry::query()->whereIn('payout_item_id', $itemIds)->where('status', 'included_in_payout')
            ->update(['status' => 'paid']);

        CommissionLedgerEntry::query()->whereIn('payout_item_id', $itemIds)->where('status', 'earned')
            ->update(['status' => 'included_in_payout']);
        CommissionLedgerEntry::query()->whereIn('payout_item_id', $itemIds)->where('status', 'included_in_payout')
            ->update(['status' => 'paid']);
    }
}
