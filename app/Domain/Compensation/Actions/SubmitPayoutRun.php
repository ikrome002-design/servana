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
use App\Domain\Compensation\Services\PayoutRunItemSnapshotter;
use App\Domain\Compensation\Services\PayoutRunStateMachine;
use App\Domain\Compensation\Services\SelectEligiblePayoutLiabilities;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Submits (FREEZES) an HR draft payout run (Plan §62; §H7; §25.4). In one transaction with the run
 * and every candidate ledger row locked FOR UPDATE: re-snapshot the items from the currently-eligible
 * facts, CLAIM each source ledger row by setting its `payout_item_id` (status untouched — the 20G
 * enums are forward-only; D-H3-2), then flip the run + items `draft → submitted`. After submit the
 * items are frozen (DB guard). Audits `payout_run.submitted` (warn).
 */
final class SubmitPayoutRun
{
    public function __construct(
        private readonly SelectEligiblePayoutLiabilities $selector,
        private readonly PayoutRunItemSnapshotter $snapshotter,
        private readonly PayoutRunStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PersonnelPayoutRun $run, User $actor): PersonnelPayoutRun
    {
        return DB::transaction(function () use ($run, $actor): PersonnelPayoutRun {
            $locked = PersonnelPayoutRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->machine->ensure($locked->status, PayoutRunStatus::Submitted);

            // Lock every candidate ledger row for the whole claim, then rebuild items from that set.
            $this->selector->forRun($locked, lock: true);
            $this->snapshotter->rebuild($locked);

            /** @var Collection<int, PersonnelPayoutItem> $items */
            $items = PersonnelPayoutItem::query()->where('payout_run_id', $locked->id)->get();

            foreach ($items as $item) {
                $refs = $item->source_ledger_refs;
                $this->claim(SalaryLedgerEntry::class, $refs['salary'] ?? [], $item->id);
                $this->claim(CommissionLedgerEntry::class, $refs['commission'] ?? [], $item->id);
                $this->claim(CompensationAdjustment::class, $refs['adjustment'] ?? [], $item->id);
            }

            $locked->status = PayoutRunStatus::Submitted;
            $locked->submitted_by = $actor->id;
            $locked->save();

            PersonnelPayoutItem::query()
                ->where('payout_run_id', $locked->id)
                ->update(['status' => PayoutItemStatus::Submitted->value]);

            $this->audit->record(
                AuditEvent::PayoutRunSubmitted,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'payout_run_id' => $locked->ulid,
                    'gross_total_minor' => $locked->gross_total_minor,
                    'item_count' => $items->count(),
                ],
            );

            return $locked->refresh();
        });
    }

    /**
     * Claim source ledger rows for a payout item. Rows are already FOR UPDATE-locked and were
     * `payout_item_id IS NULL` when selected; the guard permits the `payout_item_id` transition.
     *
     * @param  class-string<SalaryLedgerEntry|CommissionLedgerEntry|CompensationAdjustment>  $model
     * @param  list<int>  $ids
     */
    private function claim(string $model, array $ids, int $payoutItemId): void
    {
        if ($ids === []) {
            return;
        }

        $model::query()
            ->whereIn('id', $ids)
            ->whereNull('payout_item_id')
            ->update(['payout_item_id' => $payoutItemId]);
    }
}
