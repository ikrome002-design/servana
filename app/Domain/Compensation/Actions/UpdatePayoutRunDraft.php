<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Exceptions\CompensationStateException;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Services\PayoutRunItemSnapshotter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edits an HR draft payout run's period/currency and REGENERATES its snapshot items (Plan §62;
 * §H7). Allowed only while the run is `draft` (a submitted run is frozen — corrections go through
 * rejection→new draft or an adjustment run). Ledgers are not claimed here. Single transaction;
 * audits `payout_run.updated_draft` (info).
 */
final class UpdatePayoutRunDraft
{
    public function __construct(
        private readonly PayoutRunItemSnapshotter $snapshotter,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        PersonnelPayoutRun $run,
        string $periodStart,
        string $periodEnd,
        string $currency,
        User $actor,
    ): PersonnelPayoutRun {
        if ($run->status !== PayoutRunStatus::Draft) {
            throw CompensationStateException::invalidTransition('personnel payout run', $run->status->value, 'updated_draft');
        }

        return DB::transaction(function () use ($run, $periodStart, $periodEnd, $currency, $actor): PersonnelPayoutRun {
            $locked = PersonnelPayoutRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayoutRunStatus::Draft) {
                throw CompensationStateException::invalidTransition('personnel payout run', $locked->status->value, 'updated_draft');
            }

            $locked->fill([
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'currency' => $currency,
            ]);
            $locked->save();

            $this->snapshotter->rebuild($locked);

            $this->audit->record(
                AuditEvent::PayoutRunUpdatedDraft,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'payout_run_id' => $locked->ulid,
                    'period_start' => $locked->period_start->toDateString(),
                    'period_end' => $locked->period_end->toDateString(),
                    'currency' => $locked->currency,
                    'gross_total_minor' => $locked->gross_total_minor,
                    'item_count' => $locked->items()->count(),
                ],
            );

            return $locked->refresh();
        });
    }
}
