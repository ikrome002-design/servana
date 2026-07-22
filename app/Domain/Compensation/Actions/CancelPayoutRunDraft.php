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
 * HR cancels a draft payout run (Plan §62; §H5). Moves `draft → cancelled`. A draft never claimed any
 * ledger (claim happens at submit), so there is nothing to release. Items mirror the run. Single
 * transaction with a row lock; audits `payout_run.cancelled` (info).
 */
final class CancelPayoutRunDraft
{
    public function __construct(
        private readonly PayoutRunStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PersonnelPayoutRun $run, User $actor): PersonnelPayoutRun
    {
        return DB::transaction(function () use ($run, $actor): PersonnelPayoutRun {
            $locked = PersonnelPayoutRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, PayoutRunStatus::Cancelled);
            $locked->status = PayoutRunStatus::Cancelled;
            $locked->save();

            // Draft items may be DELETE-guarded once non-draft; mirror them to cancelled instead.
            PersonnelPayoutItem::query()
                ->where('payout_run_id', $locked->id)
                ->update(['status' => PayoutItemStatus::Cancelled->value]);

            $this->audit->record(
                AuditEvent::PayoutRunCancelled,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                ['payout_run_id' => $locked->ulid],
            );

            return $locked->refresh();
        });
    }
}
