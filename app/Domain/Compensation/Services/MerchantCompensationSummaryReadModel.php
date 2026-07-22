<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Models\PersonnelPayoutRun;

/**
 * Server-authoritative Merchant-Administrator compensation summary (Plan §62/§63, §19.3;
 * `merchant.compensation_summary.view`). A READ surface only — it never mutates, never recomputes
 * salary/commission (it reads the finalized 20G ledger facts through {@see CompensationLiabilityReadModel})
 * and the 20H payout-run facts, and it NEVER combines currencies. Every figure is integer minor units
 * (ADR-005) and every reference is a public aggregate; no internal id, no private staff contact, no
 * payout mutation is exposed here. Merchant-scoped (the Merchant Administrator sees the whole own
 * merchant); no Wallet/provider data.
 */
final class MerchantCompensationSummaryReadModel
{
    public function __construct(private readonly CompensationLiabilityReadModel $liabilities) {}

    /**
     * @return array{
     *     outstanding_liability_by_currency: list<array<string, int|string>>,
     *     paid_by_currency: list<array{currency: string, paid_gross_minor: int, run_count: int}>,
     *     payout_runs_by_status: array<string, int>,
     *     pending_high_value_approvals: int
     * }
     */
    public function summary(int $merchantId): array
    {
        return [
            'outstanding_liability_by_currency' => $this->liabilities->summary($merchantId, null),
            'paid_by_currency' => $this->paidByCurrency($merchantId),
            'payout_runs_by_status' => $this->runsByStatus($merchantId),
            'pending_high_value_approvals' => PersonnelPayoutRun::query()
                ->where('merchant_id', $merchantId)
                ->where('status', PayoutRunStatus::PendingMerchantAdminApproval->value)
                ->count(),
        ];
    }

    /**
     * Paid payout totals grouped by currency (never combined). Signed — clawback-heavy runs may net
     * negative (D-H9-1).
     *
     * @return list<array{currency: string, paid_gross_minor: int, run_count: int}>
     */
    private function paidByCurrency(int $merchantId): array
    {
        $rows = PersonnelPayoutRun::query()
            ->where('merchant_id', $merchantId)
            ->where('status', PayoutRunStatus::Paid->value)
            ->toBase()
            ->selectRaw('currency, sum(gross_total_minor) as paid_gross_minor, count(*) as run_count')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();

        /** @var list<array{currency: string, paid_gross_minor: int, run_count: int}> $out */
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'currency' => (string) $r->currency,
                'paid_gross_minor' => (int) $r->paid_gross_minor,
                'run_count' => (int) $r->run_count,
            ];
        }

        return $out;
    }

    /**
     * Payout-run counts keyed by every status value (zero-filled), so the summary shape is stable.
     *
     * @return array<string, int>
     */
    private function runsByStatus(int $merchantId): array
    {
        $counts = PersonnelPayoutRun::query()
            ->where('merchant_id', $merchantId)
            ->toBase()
            ->selectRaw('status, count(*) as run_count')
            ->groupBy('status')
            ->pluck('run_count', 'status');

        $out = [];
        foreach (PayoutRunStatus::values() as $status) {
            $out[$status] = (int) ($counts[$status] ?? 0);
        }

        return $out;
    }
}
