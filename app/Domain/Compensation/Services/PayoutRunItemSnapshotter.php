<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Enums\PayoutItemStatus;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Compensation\Models\PersonnelPayoutRun;

/**
 * (Re)builds the draft snapshot items of a payout run from the currently-eligible 20G ledger facts
 * (Plan §62; §H4/§H7). One item per staff_profile in the run currency, with bucketed sums + the exact
 * snapshotted row ids in `source_ledger_refs`. Regeneration deletes existing DRAFT items and inserts
 * fresh ones (allowed only while the run is draft — the DB freeze guard blocks it otherwise). Ledgers
 * are NOT claimed here (that happens at submit); this is a snapshot preview. Recomputes the run's
 * `gross_total_minor`. Must run inside a DB transaction owned by the caller.
 */
final class PayoutRunItemSnapshotter
{
    public function __construct(private readonly SelectEligiblePayoutLiabilities $selector) {}

    public function rebuild(PersonnelPayoutRun $run): void
    {
        // Clear any prior draft snapshot (regeneration). The DB guard permits DELETE only while draft.
        PersonnelPayoutItem::query()->where('payout_run_id', $run->id)->delete();

        $eligible = $this->selector->forRun($run);

        /** @var array<int, array{salary: list<int>, commission: list<int>, adjustment: list<int>, salary_minor: int, commission_minor: int, adjustment_minor: int}> $byStaff */
        $byStaff = [];

        foreach ($eligible['salary'] as $row) {
            $byStaff[$row->staff_profile_id] ??= $this->emptyBucket();
            $byStaff[$row->staff_profile_id]['salary'][] = $row->id;
            $byStaff[$row->staff_profile_id]['salary_minor'] += $row->amount_minor;
        }
        foreach ($eligible['commission'] as $row) {
            $byStaff[$row->staff_profile_id] ??= $this->emptyBucket();
            $byStaff[$row->staff_profile_id]['commission'][] = $row->id;
            $byStaff[$row->staff_profile_id]['commission_minor'] += $row->amount_minor;
        }
        foreach ($eligible['adjustment'] as $row) {
            $byStaff[$row->staff_profile_id] ??= $this->emptyBucket();
            $byStaff[$row->staff_profile_id]['adjustment'][] = $row->id;
            $byStaff[$row->staff_profile_id]['adjustment_minor'] += $row->amount_minor;
        }

        $grossTotal = 0;

        foreach ($byStaff as $staffProfileId => $bucket) {
            $gross = $bucket['salary_minor'] + $bucket['commission_minor'] + $bucket['adjustment_minor'];
            $grossTotal += $gross;

            PersonnelPayoutItem::create([
                'merchant_id' => $run->merchant_id,
                'branch_id' => $run->branch_id,
                'payout_run_id' => $run->id,
                'staff_profile_id' => $staffProfileId,
                'currency' => $run->currency,
                'salary_amount_minor' => $bucket['salary_minor'],
                'commission_amount_minor' => $bucket['commission_minor'],
                'adjustment_amount_minor' => $bucket['adjustment_minor'],
                'gross_amount_minor' => $gross,
                'source_ledger_refs' => [
                    'salary' => $bucket['salary'],
                    'commission' => $bucket['commission'],
                    'adjustment' => $bucket['adjustment'],
                ],
                'status' => PayoutItemStatus::Draft->value,
            ]);
        }

        $run->gross_total_minor = $grossTotal;
        $run->save();
    }

    /** @return array{salary: list<int>, commission: list<int>, adjustment: list<int>, salary_minor: int, commission_minor: int, adjustment_minor: int} */
    private function emptyBucket(): array
    {
        return [
            'salary' => [],
            'commission' => [],
            'adjustment' => [],
            'salary_minor' => 0,
            'commission_minor' => 0,
            'adjustment_minor' => 0,
        ];
    }
}
