<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\PayoutItemStatus;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Services\PayoutRunStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Finance verifies a submitted payout run (Plan §62; §H5; §25.5). Moves `submitted →
 * finance_verified`, then ROUTES by value: a high-value run (gross > snapshotted threshold, threshold
 * not null) auto-advances to `pending_merchant_admin_approval` for Merchant-Admin approval; an
 * ordinary run stays `finance_verified` for Finance standard approval. Items mirror the run. Single
 * transaction with a row lock; audits `payout_run.verified` (high).
 */
final class VerifyPayoutRun
{
    public function __construct(
        private readonly PayoutRunStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PersonnelPayoutRun $run, User $actor): PersonnelPayoutRun
    {
        return DB::transaction(function () use ($run, $actor): PersonnelPayoutRun {
            $locked = PersonnelPayoutRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, PayoutRunStatus::FinanceVerified);
            $locked->status = PayoutRunStatus::FinanceVerified;
            $locked->verified_by = $actor->id;
            $locked->save();

            $highValue = $this->isHighValue($locked);
            if ($highValue) {
                $this->machine->ensure($locked->status, PayoutRunStatus::PendingMerchantAdminApproval);
                $locked->status = PayoutRunStatus::PendingMerchantAdminApproval;
                $locked->save();
            }

            $this->mirrorItems($locked);

            $this->audit->record(
                AuditEvent::PayoutRunVerified,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'payout_run_id' => $locked->ulid,
                    'gross_total_minor' => $locked->gross_total_minor,
                    'high_value' => $highValue,
                    'high_value_threshold_snapshot_minor' => $locked->high_value_threshold_snapshot_minor,
                    'routed_to' => $locked->status->value,
                ],
            );

            return $locked->refresh();
        });
    }

    private function isHighValue(PersonnelPayoutRun $run): bool
    {
        return $run->high_value_threshold_snapshot_minor !== null
            && $run->gross_total_minor > $run->high_value_threshold_snapshot_minor;
    }

    private function mirrorItems(PersonnelPayoutRun $run): void
    {
        PersonnelPayoutItem::query()
            ->where('payout_run_id', $run->id)
            ->update(['status' => PayoutItemStatus::mirror($run->status)->value]);
    }
}
