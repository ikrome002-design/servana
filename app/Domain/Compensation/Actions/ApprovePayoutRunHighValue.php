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
 * Merchant-Admin high-value approval of a payout run (Plan §62; §H5). Moves
 * `pending_merchant_admin_approval → approved` — only reachable after Finance verification routed the
 * run there because gross exceeded the snapshotted threshold. Items mirror the run. Single
 * transaction with a row lock; audits `payout_run.high_value_approved` (critical).
 */
final class ApprovePayoutRunHighValue
{
    public function __construct(
        private readonly PayoutRunStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PersonnelPayoutRun $run, User $actor): PersonnelPayoutRun
    {
        return DB::transaction(function () use ($run, $actor): PersonnelPayoutRun {
            $locked = PersonnelPayoutRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            // High-value approval applies ONLY to a run awaiting Merchant-Admin approval.
            if ($locked->status !== PayoutRunStatus::PendingMerchantAdminApproval) {
                throw CompensationStateException::invalidTransition('personnel payout run', $locked->status->value, 'high_value_approved');
            }

            $this->machine->ensure($locked->status, PayoutRunStatus::Approved);
            $locked->status = PayoutRunStatus::Approved;
            $locked->approved_by = $actor->id;
            $locked->save();

            PersonnelPayoutItem::query()
                ->where('payout_run_id', $locked->id)
                ->update(['status' => PayoutItemStatus::Approved->value]);

            $this->audit->record(
                AuditEvent::PayoutRunHighValueApproved,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'payout_run_id' => $locked->ulid,
                    'gross_total_minor' => $locked->gross_total_minor,
                    'high_value_threshold_snapshot_minor' => $locked->high_value_threshold_snapshot_minor,
                ],
            );

            return $locked->refresh();
        });
    }
}
