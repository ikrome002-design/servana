<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Services\PayoutRunItemSnapshotter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates an HR draft payout run and snapshots the currently-eligible 20G ledger facts into items
 * (Plan §62; §H6/§H7). The high-value threshold is SNAPSHOTTED from the merchant's compensation
 * settings (`merchant_subscriptions.high_value_payout_threshold_minor`, Phase 20A) — never hardcoded;
 * a null snapshot leaves the high-value approval gate inactive. Ledgers are NOT yet claimed (that is
 * the submit/freeze step). Single transaction; audits `payout_run.created` (warn).
 */
final class CreatePayoutRunDraft
{
    public function __construct(
        private readonly PayoutRunItemSnapshotter $snapshotter,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        MerchantBranch $branch,
        string $periodStart,
        string $periodEnd,
        string $currency,
        User $createdBy,
    ): PersonnelPayoutRun {
        return DB::transaction(function () use ($branch, $periodStart, $periodEnd, $currency, $createdBy): PersonnelPayoutRun {
            $run = PersonnelPayoutRun::create([
                'merchant_id' => $branch->merchant_id,
                'branch_id' => $branch->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'currency' => $currency,
                'high_value_threshold_snapshot_minor' => $this->thresholdFor($branch->merchant_id),
                'status' => PayoutRunStatus::Draft->value,
                'gross_total_minor' => 0,
                'created_by' => $createdBy->id,
            ]);

            $this->snapshotter->rebuild($run);

            $this->audit->record(
                AuditEvent::PayoutRunCreated,
                $createdBy,
                $run->merchant_id,
                $run->branch_id,
                $run,
                $this->context($run->refresh()),
            );

            return $run;
        });
    }

    private function thresholdFor(int $merchantId): ?int
    {
        /** @var int|null $threshold */
        $threshold = MerchantSubscription::query()
            ->where('merchant_id', $merchantId)
            ->latest('id')
            ->value('high_value_payout_threshold_minor');

        return $threshold;
    }

    /** @return array<string, mixed> */
    private function context(PersonnelPayoutRun $run): array
    {
        return [
            'payout_run_id' => $run->ulid,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'currency' => $run->currency,
            'gross_total_minor' => $run->gross_total_minor,
            'item_count' => $run->items()->count(),
            'high_value_threshold_snapshot_minor' => $run->high_value_threshold_snapshot_minor,
        ];
    }
}
