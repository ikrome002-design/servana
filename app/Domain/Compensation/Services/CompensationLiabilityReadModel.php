<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\CompensationAdjustment;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Server-authoritative Phase 20G compensation-liability read model (Plan §61/§80; `compensation
 * .liability.view`). Reads the append-only salary_ledger / commission_ledger / compensation_adjustments
 * facts and derives per-currency liability totals + a normalized, masked entry projection. It never
 * mutates, never sums across currencies, and exposes only safe references (public ULIDs, integer minor
 * units) — no internal ids, no private contact data. `BelongsToMerchant` bounds every query to the
 * actor's merchant; an optional branch restriction narrows it to the actor's assigned branches or an
 * explicit branch filter.
 *
 * Liability semantics ("earned-unpaid balance"; §9.2):
 *   net salary liability     = Σ salary_ledger.amount_minor WHERE status <> 'paid'
 *   net commission liability = Σ commission_ledger.amount_minor WHERE status NOT IN ('paid','cancelled')
 *   adjustments              = Σ compensation_adjustments.amount_minor
 *   combined net             = net salary + net commission + adjustments   (per currency only)
 * A reversed original (+) and its exact-negative reversal row (−) net to zero, so the balance is truthful
 * without special-casing. Money is integer minor units (ADR-005); different currencies are never combined.
 */
final class CompensationLiabilityReadModel
{
    /**
     * Per-currency liability totals for the merchant (optionally restricted to branches).
     *
     * @param  list<int>|null  $branchIds  null = whole merchant; a list restricts to those branches
     * @param  array{staff_profile_id?: int|null, currency?: string|null}  $filters
     * @return list<array<string, int|string>>
     */
    public function summary(int $merchantId, ?array $branchIds, array $filters = []): array
    {
        $salary = $this->groupedTotals(
            SalaryLedgerEntry::query(),
            $merchantId,
            $branchIds,
            $filters,
            "sum(case when entry_type = 'accrual' then amount_minor else 0 end) as gross_minor",
            "sum(case when entry_type = 'reversal' then amount_minor else 0 end) as reversal_minor",
            "sum(case when status <> 'paid' then amount_minor else 0 end) as net_minor",
        );

        $commission = $this->groupedTotals(
            CommissionLedgerEntry::query(),
            $merchantId,
            $branchIds,
            $filters,
            "sum(case when entry_type = 'earned' then amount_minor else 0 end) as gross_minor",
            "sum(case when entry_type = 'reversal' then amount_minor else 0 end) as reversal_minor",
            "sum(case when status not in ('paid','cancelled') then amount_minor else 0 end) as net_minor",
        );

        $adjustments = $this->groupedTotals(
            CompensationAdjustment::query(),
            $merchantId,
            $branchIds,
            $filters,
            'sum(amount_minor) as gross_minor',
            'sum(0) as reversal_minor',
            'sum(amount_minor) as net_minor',
        );

        $currencies = collect([$salary, $commission, $adjustments])
            ->flatMap(static fn (array $rows): array => array_keys($rows))
            ->unique()
            ->sort()
            ->values();

        /** @var list<array<string, int|string>> $rows */
        $rows = $currencies->map(static function (string $currency) use ($salary, $commission, $adjustments): array {
            $s = $salary[$currency] ?? ['gross' => 0, 'reversal' => 0, 'net' => 0];
            $c = $commission[$currency] ?? ['gross' => 0, 'reversal' => 0, 'net' => 0];
            $a = $adjustments[$currency] ?? ['gross' => 0, 'reversal' => 0, 'net' => 0];

            return [
                'currency' => $currency,
                'gross_salary_accrual_minor' => $s['gross'],
                'salary_reversal_minor' => $s['reversal'],
                'net_salary_liability_minor' => $s['net'],
                'gross_earned_commission_minor' => $c['gross'],
                'commission_reversal_minor' => $c['reversal'],
                'net_commission_liability_minor' => $c['net'],
                'compensation_adjustment_minor' => $a['net'],
                'combined_net_liability_minor' => $s['net'] + $c['net'] + $a['net'],
            ];
        })->values()->all();

        return $rows;
    }

    /**
     * @param  Builder<SalaryLedgerEntry>|Builder<CommissionLedgerEntry>|Builder<CompensationAdjustment>  $query
     * @param  list<int>|null  $branchIds
     * @param  array{staff_profile_id?: int|null, currency?: string|null}  $filters
     * @return array<string, array{gross: int, reversal: int, net: int}>
     */
    private function groupedTotals(Builder $query, int $merchantId, ?array $branchIds, array $filters, string $grossExpr, string $reversalExpr, string $netExpr): array
    {
        $query->where('merchant_id', $merchantId);
        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds);
        }
        if (($filters['staff_profile_id'] ?? null) !== null) {
            $query->where('staff_profile_id', $filters['staff_profile_id']);
        }
        if (($filters['currency'] ?? null) !== null) {
            $query->where('currency', $filters['currency']);
        }

        return $query->toBase()
            ->selectRaw('currency')
            ->selectRaw($grossExpr)
            ->selectRaw($reversalExpr)
            ->selectRaw($netExpr)
            ->groupBy('currency')
            ->get()
            ->mapWithKeys(static fn (object $r): array => [(string) $r->currency => [
                'gross' => (int) $r->gross_minor,
                'reversal' => (int) $r->reversal_minor,
                'net' => (int) $r->net_minor,
            ]])
            ->all();
    }

    /**
     * Normalized, masked, paginated liability entries across the salary + commission ledgers. The union
     * is bounded to one merchant (and optionally its branches), so it is fetched under the applied filters
     * and paginated deterministically by business date desc, then public ULID desc.
     *
     * @param  list<int>|null  $branchIds
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function entries(int $merchantId, ?array $branchIds, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $type = $filters['liability_type'] ?? null;

        $rows = collect();
        if ($type === null || $type === 'salary') {
            $rows = $rows->concat($this->salaryEntries($merchantId, $branchIds, $filters));
        }
        if ($type === null || $type === 'commission') {
            $rows = $rows->concat($this->commissionEntries($merchantId, $branchIds, $filters));
        }

        $sorted = $rows->sortByDesc('business_date')
            ->values()
            ->sortByDesc(fn (array $r): string => $r['business_date'].$r['ledger_ulid'])
            ->values();

        $total = $sorted->count();
        $slice = $sorted->forPage($page, $perPage)->values()->all();

        return new ConcretePaginator($slice, $total, $perPage, $page, ['path' => ConcretePaginator::resolveCurrentPath()]);
    }

    /**
     * @param  list<int>|null  $branchIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function salaryEntries(int $merchantId, ?array $branchIds, array $filters): Collection
    {
        $query = SalaryLedgerEntry::query()
            ->with(['staffProfile:id,ulid,display_name', 'branch:id,ulid', 'compensationPlan:id,ulid'])
            ->where('merchant_id', $merchantId);
        $this->applyCommonFilters($query, $branchIds, $filters, 'pay_period_end');

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $query->get()->map(fn (SalaryLedgerEntry $e): array => [
            'ledger_ulid' => $e->ulid,
            'liability_type' => 'salary',
            'entry_type' => $e->entry_type->value,
            'status' => $e->status->value,
            'amount_minor' => (int) $e->amount_minor,
            'currency' => $e->currency,
            'business_date' => Carbon::parse($e->pay_period_end)->toDateString(),
            'staff_profile_ulid' => $e->staffProfile?->ulid,
            'staff_display_name' => $e->staffProfile?->display_name,
            'branch_ulid' => $e->branch?->ulid,
            'compensation_plan_ulid' => $e->compensationPlan?->ulid,
            'commission_rule_ulid' => null,
            'pay_period_start' => Carbon::parse($e->pay_period_start)->toDateString(),
            'pay_period_end' => Carbon::parse($e->pay_period_end)->toDateString(),
            'invoice_reference' => null,
            'source_entry_ulid' => $this->sourceUlid($e->source_entry_id, SalaryLedgerEntry::class),
            'created_at' => $e->created_at?->toIso8601String(),
        ]);

        return $rows;
    }

    /**
     * @param  list<int>|null  $branchIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function commissionEntries(int $merchantId, ?array $branchIds, array $filters): Collection
    {
        $query = CommissionLedgerEntry::query()
            ->with(['staffProfile:id,ulid,display_name', 'branch:id,ulid', 'compensationPlan:id,ulid', 'commissionRule:id,ulid', 'invoice:id,ulid,invoice_number'])
            ->where('merchant_id', $merchantId);
        $this->applyCommonFilters($query, $branchIds, $filters, 'earned_at');

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $query->get()->map(fn (CommissionLedgerEntry $e): array => [
            'ledger_ulid' => $e->ulid,
            'liability_type' => 'commission',
            'entry_type' => $e->entry_type->value,
            'status' => $e->status->value,
            'amount_minor' => (int) $e->amount_minor,
            'currency' => $e->currency,
            'business_date' => ($e->earned_at ?? $e->created_at)?->toDateString(),
            'staff_profile_ulid' => $e->staffProfile?->ulid,
            'staff_display_name' => $e->staffProfile?->display_name,
            'branch_ulid' => $e->branch?->ulid,
            'compensation_plan_ulid' => $e->compensationPlan?->ulid,
            'commission_rule_ulid' => $e->commissionRule?->ulid,
            'pay_period_start' => null,
            'pay_period_end' => null,
            'invoice_reference' => $e->invoice?->invoice_number,
            'source_entry_ulid' => $this->sourceUlid($e->source_entry_id, CommissionLedgerEntry::class),
            'created_at' => $e->created_at?->toIso8601String(),
        ]);

        return $rows;
    }

    /**
     * @param  Builder<SalaryLedgerEntry>|Builder<CommissionLedgerEntry>  $query
     * @param  list<int>|null  $branchIds
     * @param  array<string, mixed>  $filters
     */
    private function applyCommonFilters(Builder $query, ?array $branchIds, array $filters, string $dateColumn): void
    {
        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds);
        }
        if (($filters['staff_profile_id'] ?? null) !== null) {
            $query->where('staff_profile_id', $filters['staff_profile_id']);
        }
        if (($filters['currency'] ?? null) !== null) {
            $query->where('currency', $filters['currency']);
        }
        if (($filters['entry_type'] ?? null) !== null) {
            $query->where('entry_type', $filters['entry_type']);
        }
        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }
        if (($filters['date_from'] ?? null) !== null) {
            $query->whereDate($dateColumn, '>=', $filters['date_from']);
        }
        if (($filters['date_to'] ?? null) !== null) {
            $query->whereDate($dateColumn, '<=', $filters['date_to']);
        }
    }

    /**
     * @param  class-string<SalaryLedgerEntry|CommissionLedgerEntry>  $model
     */
    private function sourceUlid(?int $sourceEntryId, string $model): ?string
    {
        if ($sourceEntryId === null) {
            return null;
        }

        return $model::query()->whereKey($sourceEntryId)->value('ulid');
    }
}
