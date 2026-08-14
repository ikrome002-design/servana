<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Services;

use App\Domain\Branches\Enums\CashUpStatus;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Compensation\Enums\CommissionLedgerStatus;
use App\Domain\Compensation\Enums\EarningsQueryStatus;
use App\Domain\Compensation\Enums\PayoutRunStatus;
use App\Domain\Compensation\Enums\SalaryLedgerStatus;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\EarningsQuery;
use App\Domain\Compensation\Models\PersonnelPayoutRun;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\FinanceOps\Enums\FinanceDisputeStatus;
use App\Domain\FinanceOps\Enums\FinancialPeriodLockStatus;
use App\Domain\FinanceOps\Models\FinanceDispute;
use App\Domain\FinanceOps\Models\FinancialPeriodLock;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentReferenceCheckResult;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use App\Domain\Receipts\Models\Receipt;
use App\Domain\Refunds\Enums\RefundStatus;
use App\Domain\Refunds\Models\Refund;
use App\Domain\Tenancy\TenantContext;
use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Truthful Finance presentation read over already-shipped financial facts.
 *
 * Every model keeps its merchant/branch global scopes. Amounts are grouped by
 * currency before formatting, so the dashboard never combines currencies. This
 * service creates no financial state and exposes no Wallet/report/notification
 * placeholder values while their owning phases remain gated.
 */
final class FinanceWorkspaceReadModel
{
    public const WALLET_GATE_REASON = 'External Gate W is closed. Phase 20D-W has no Wallet payment, attempt, allocation or reconciliation runtime.';

    public const PHASE_21N_GATE_REASON = 'Phase 21N reporting and notification runtime is blocked until External Gate W and Phase 20D-W complete.';

    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        $branches = $this->branches();
        $pendingStatuses = PaymentRecordingGroupStatus::values(PaymentRecordingGroupStatus::activePendingStatuses());
        $pendingGroups = PaymentRecordingGroup::query()->whereIn('status', $pendingStatuses);
        $outstandingInvoices = Invoice::query()->whereIn('status', [
            InvoiceStatus::Issued->value,
            InvoiceStatus::PartiallyPaid->value,
            InvoiceStatus::RefundPending->value,
            InvoiceStatus::VoidPending->value,
        ]);

        $pendingValidationCount = PaymentRecordingGroup::query()
            ->where('status', PaymentRecordingGroupStatus::PendingValidation->value)
            ->count();
        $duplicateCount = PaymentReferenceCheck::query()
            ->where('result', PaymentReferenceCheckResult::DuplicateSuspected->value)
            ->whereNull('override_by')
            ->count();
        $activeDisputes = FinanceDispute::query()
            ->whereIn('status', [FinanceDisputeStatus::Open->value, FinanceDisputeStatus::UnderReview->value])
            ->count();
        $refundsRequiringAction = Refund::query()
            ->whereIn('status', [RefundStatus::Requested->value, RefundStatus::Approved->value])
            ->count();
        $cashUpsRequiringReview = BranchCashUp::query()
            ->where('status', CashUpStatus::Submitted->value)
            ->count();
        $reopenRequests = FinancialPeriodLock::query()
            ->where('status', FinancialPeriodLockStatus::Locked->value)
            ->whereNotNull('reopen_requested_at')
            ->whereNull('reopened_at')
            ->count();
        $payoutsRequiringAction = PersonnelPayoutRun::query()
            ->whereIn('status', [
                PayoutRunStatus::Submitted->value,
                PayoutRunStatus::FinanceVerified->value,
                PayoutRunStatus::Approved->value,
            ])
            ->count();
        $earningsQueriesRequiringAction = EarningsQuery::query()
            ->whereIn('status', [EarningsQueryStatus::Open->value, EarningsQueryStatus::Assigned->value])
            ->count();

        return [
            'branch_context' => [
                'label' => $branches->count() === 1
                    ? (string) $branches->first()?->name
                    : $branches->count().' assigned branches',
                'branches' => $branches->map(static fn (MerchantBranch $branch): array => [
                    'id' => $branch->ulid,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'town' => $branch->town,
                ])->values()->all(),
            ],
            'payments' => [
                'pending_validation' => $pendingValidationCount,
                'duplicate_risk' => $duplicateCount,
                'pending_recorded' => $this->moneyRows((clone $pendingGroups)
                    ->selectRaw('currency, SUM(total_amount_minor) AS amount_minor')
                    ->groupBy('currency')
                    ->orderBy('currency')
                    ->get()),
            ],
            'invoices' => [
                'outstanding' => (clone $outstandingInvoices)->count(),
                'outstanding_balance' => $this->moneyRows((clone $outstandingInvoices)
                    ->selectRaw('currency, SUM(GREATEST(total_minor - validated_paid_minor, 0)) AS amount_minor')
                    ->groupBy('currency')
                    ->orderBy('currency')
                    ->get()),
                'validated_payments' => $this->moneyRows(Invoice::query()
                    ->where('validated_paid_minor', '>', 0)
                    ->selectRaw('currency, SUM(validated_paid_minor) AS amount_minor')
                    ->groupBy('currency')
                    ->orderBy('currency')
                    ->get()),
            ],
            'controls' => [
                'original_receipts' => Receipt::query()->whereNull('reissue_of_receipt_id')->count(),
                'active_disputes' => $activeDisputes,
                'refunds_requiring_action' => $refundsRequiringAction,
                'cash_ups_requiring_review' => $cashUpsRequiringReview,
                'open_periods' => FinancialPeriodLock::query()->where('status', FinancialPeriodLockStatus::Open->value)->count(),
                'reopen_requests' => $reopenRequests,
            ],
            'compensation' => [
                'salary_due' => $this->moneyRows(SalaryLedgerEntry::query()
                    ->where('status', SalaryLedgerStatus::Pending->value)
                    ->selectRaw('currency, SUM(amount_minor) AS amount_minor')
                    ->groupBy('currency')
                    ->orderBy('currency')
                    ->get()),
                'commission_due' => $this->moneyRows(CommissionLedgerEntry::query()
                    ->where('status', CommissionLedgerStatus::Earned->value)
                    ->selectRaw('currency, SUM(amount_minor) AS amount_minor')
                    ->groupBy('currency')
                    ->orderBy('currency')
                    ->get()),
                'payouts_requiring_action' => $payoutsRequiringAction,
                'earnings_queries_requiring_action' => $earningsQueriesRequiringAction,
            ],
            'tasks' => [
                $this->task('payment-validations', 'Payment groups awaiting validation', $pendingValidationCount, 'high', 'finance.payments-validations', false),
                $this->task('duplicate-references', 'Duplicate references held for review', $duplicateCount, 'critical', 'finance.payments-duplicates', true),
                $this->task('cash-up-review', 'Cash-ups awaiting checker review', $cashUpsRequiringReview, 'high', 'finance.cash-up', false),
                $this->task('refunds', 'External refunds awaiting a decision', $refundsRequiringAction, 'high', 'finance.refunds', true),
                $this->task('disputes', 'Open finance disputes', $activeDisputes, 'medium', 'finance.disputes', false),
                $this->task('period-reopen', 'Period reopen requests', $reopenRequests, 'critical', 'finance.periods', true),
                $this->task('payouts', 'Payout runs awaiting Finance', $payoutsRequiringAction, 'high', 'finance.payouts', true),
                $this->task('earnings-queries', 'Earnings queries awaiting response', $earningsQueriesRequiringAction, 'medium', 'finance.compensation-queries', false),
            ],
            'subscription' => ['available' => false, 'reason' => self::WALLET_GATE_REASON],
            'reports' => ['available' => false, 'reason' => self::PHASE_21N_GATE_REASON],
            'notifications' => ['available' => false, 'reason' => self::PHASE_21N_GATE_REASON],
        ];
    }

    /** @return Collection<int, MerchantBranch> */
    private function branches(): Collection
    {
        $branchIds = $this->context->branchIds();
        abort_if($branchIds === [], 403);

        return MerchantBranch::query()
            ->whereIn('id', $branchIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $rows
     * @return list<array{amount: int, currency: string, formatted: string}>
     */
    private function moneyRows(Collection $rows): array
    {
        return array_values($rows->map(static function (Model $row): array {
            $currency = Currency::from((string) $row->getAttribute('currency'));
            $amount = Money::ofMinor((int) $row->getAttribute('amount_minor'), $currency)->toArray();

            return [
                'amount' => $amount['amount'],
                'currency' => $amount['currency'],
                'formatted' => $amount['formatted'],
            ];
        })->all());
    }

    /** @return array{key: string, label: string, count: int, severity: string, route_name: string, step_up_required: bool, maker_checker: string} */
    private function task(
        string $key,
        string $label,
        int $count,
        string $severity,
        string $routeName,
        bool $stepUpRequired,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'severity' => $severity,
            'route_name' => $routeName,
            'step_up_required' => $stepUpRequired,
            'maker_checker' => 'Finance checker',
        ];
    }
}
