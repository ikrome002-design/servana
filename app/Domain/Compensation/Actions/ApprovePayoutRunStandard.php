<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\PayoutItemStatus;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Services\PayoutRunStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Finance ordinary approval of a payout run (Plan §62; §H5). Moves `finance_verified → approved`. A
 * high-value run is never in `finance_verified` (verify auto-routes it to
 * `pending_merchant_admin_approval`), so the state guard alone keeps standard approval off high-value
 * runs. Items mirror the run. Single transaction with a row lock; audits
 * `payout_run.approved_standard` (high).
 */
final class ApprovePayoutRunStandard
{
    public function __construct(
        private readonly PayoutRunStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PersonnelPayoutRun $run, User $actor): PersonnelPayoutRun
    {
        return DB::transaction(function () use ($run, $actor): PersonnelPayoutRun {
            $locked = PersonnelPayoutRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            // Ordinary approval applies ONLY to a finance_verified run; a high-value run sits in
            // pending_merchant_admin_approval (which the shared machine would also let reach approved).
            if ($locked->status !== PayoutRunStatus::FinanceVerified) {
                throw CompensationStateException::invalidTransition('personnel payout run', $locked->status->value, 'approved_standard');
            }

            $this->machine->ensure($locked->status, PayoutRunStatus::Approved);
            $locked->status = PayoutRunStatus::Approved;
            $locked->approved_by = $actor->id;
            $locked->save();

            PersonnelPayoutItem::query()
                ->where('payout_run_id', $locked->id)
                ->update(['status' => PayoutItemStatus::Approved->value]);

            $this->audit->record(
                AuditEvent::PayoutRunApprovedStandard,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'payout_run_id' => $locked->ulid,
                    'gross_total_minor' => $locked->gross_total_minor,
                ],
            );

            return $locked->refresh();
        });
    }
}
