<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Enums\MerchantSubscriptionStatus;
use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Models\BillingEscalationEvent;
use App\Domain\Billing\Models\MerchantSubscription;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Billing\Models\SubscriptionInvoiceItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Platform subscription-operations read projection (COR-UI08-001 §10; Phase UI-08).
 *
 * READ-ONLY BY CONSTRUCTION. This class only ever builds SELECT queries. It creates no table,
 * advances no state machine, recomputes no invoice and records no payment — it explains the
 * existing Phase 20B truth to a Super Administrator and nothing more.
 * `Ui08NoForbiddenPlatformCapabilityTest` proves no platform mutation route exists to pair with it.
 *
 * NO TENANCY ESCAPE HATCH. `MerchantScope` filters only when a merchant is resolved and
 * `ResolvePlatformContext` binds none, so these tenant-owned models read across merchants
 * naturally — tenant isolation stays fully intact for every other caller.
 *
 * Every collection paginates, every filter is validated upstream, and every sort comes from an
 * ALLOWLIST here rather than from the request, so a caller cannot sort by an unindexed or
 * sensitive column.
 */
final class PlatformSubscriptionOperationsProjection
{
    /** Sorts a caller may ask for, mapped to the column that actually backs them. */
    private const SUBSCRIPTION_SORTS = [
        'current_period_end' => 'current_period_end',
        'trial_ends_at' => 'trial_ends_at',
        'created_at' => 'created_at',
    ];

    private const INVOICE_SORTS = [
        'issued_at' => 'issued_at',
        'due_at' => 'due_at',
        'total_minor' => 'total_minor',
        'created_at' => 'created_at',
    ];

    /**
     * Counts by subscription status, billing status and lifecycle cohort.
     *
     * Every figure carries its own definition and time range in the Resource, because a bare
     * number on a platform dashboard is not evidence of anything.
     *
     * @return array{
     *     as_of:string,
     *     subscriptions_by_status:array<string,int>,
     *     invoices_by_status:array<string,int>,
     *     cohorts:array{trialing:int,in_grace:int,overdue:int,suspended_billing:int,cancelled_or_expired:int},
     *     funnel:array{trial_started:int,converted_to_active:int,lapsed:int},
     *     totals:array{subscriptions:int,invoices:int,open_invoice_balance_minor:int}
     * }
     */
    public function summary(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $byStatus = [];
        foreach (MerchantSubscriptionStatus::cases() as $case) {
            $byStatus[$case->value] = 0;
        }
        // The query builder, not the model: an aggregate row is not a MerchantSubscription, and
        // reading `total` off the model would be reading a column that does not exist on it.
        foreach (DB::table('merchant_subscriptions')->selectRaw('status, count(*) as total')->groupBy('status')->get() as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $byStatus[(string) ($values['status'] ?? '')] = (int) ($values['total'] ?? 0);
        }

        $invoicesByStatus = [];
        foreach (SubscriptionInvoiceStatus::cases() as $case) {
            $invoicesByStatus[$case->value] = 0;
        }
        foreach (DB::table('subscription_invoices')->selectRaw('status, count(*) as total')->groupBy('status')->get() as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $invoicesByStatus[(string) ($values['status'] ?? '')] = (int) ($values['total'] ?? 0);
        }

        $trialStarted = $byStatus[MerchantSubscriptionStatus::Trialing->value]
            + $byStatus[MerchantSubscriptionStatus::Active->value]
            + $byStatus[MerchantSubscriptionStatus::Cancelled->value]
            + $byStatus[MerchantSubscriptionStatus::Expired->value];

        return [
            'as_of' => $asOf->toIso8601String(),
            'subscriptions_by_status' => $byStatus,
            'invoices_by_status' => $invoicesByStatus,
            'cohorts' => [
                'trialing' => $byStatus[MerchantSubscriptionStatus::Trialing->value],
                'in_grace' => $byStatus[MerchantSubscriptionStatus::ReadOnlyGrace->value],
                'overdue' => $byStatus[MerchantSubscriptionStatus::Overdue->value],
                'suspended_billing' => $byStatus[MerchantSubscriptionStatus::SuspendedBilling->value],
                'cancelled_or_expired' => $byStatus[MerchantSubscriptionStatus::Cancelled->value]
                    + $byStatus[MerchantSubscriptionStatus::Expired->value],
            ],
            'funnel' => [
                'trial_started' => $trialStarted,
                'converted_to_active' => $byStatus[MerchantSubscriptionStatus::Active->value],
                'lapsed' => $byStatus[MerchantSubscriptionStatus::Cancelled->value]
                    + $byStatus[MerchantSubscriptionStatus::Expired->value],
            ],
            'totals' => [
                'subscriptions' => array_sum($byStatus),
                'invoices' => array_sum($invoicesByStatus),
                'open_invoice_balance_minor' => (int) SubscriptionInvoice::query()
                    ->whereIn('status', [
                        SubscriptionInvoiceStatus::Issued->value,
                        SubscriptionInvoiceStatus::PendingPayment->value,
                        SubscriptionInvoiceStatus::PartiallyPaid->value,
                        SubscriptionInvoiceStatus::Overdue->value,
                    ])
                    ->sum('balance_minor'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator<int, MerchantSubscription>
     */
    public function subscriptions(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = MerchantSubscription::query()->with(['merchant', 'plan', 'price']);

        $this->applySubscriptionFilters($query, $filters);

        $sort = self::SUBSCRIPTION_SORTS[$filters['sort'] ?? ''] ?? 'current_period_end';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        /** @var LengthAwarePaginator<int, MerchantSubscription> $page */
        $page = $query->orderBy($sort, $direction)->orderBy('id')->paginate($perPage);

        return $page;
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator<int, SubscriptionInvoice>
     */
    public function invoices(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = SubscriptionInvoice::query()->with(['merchant', 'plan']);

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['merchant_id'] ?? null) !== null) {
            $query->where('merchant_id', $filters['merchant_id']);
        }

        if (($filters['issued_from'] ?? null) !== null) {
            $query->where('issued_at', '>=', $filters['issued_from']);
        }

        if (($filters['issued_to'] ?? null) !== null) {
            $query->where('issued_at', '<', $filters['issued_to']);
        }

        if (($filters['due_from'] ?? null) !== null) {
            $query->where('due_at', '>=', $filters['due_from']);
        }

        if (($filters['due_to'] ?? null) !== null) {
            $query->where('due_at', '<', $filters['due_to']);
        }

        $sort = self::INVOICE_SORTS[$filters['sort'] ?? ''] ?? 'issued_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        /** @var LengthAwarePaginator<int, SubscriptionInvoice> $page */
        $page = $query->orderBy($sort, $direction)->orderBy('id')->paginate($perPage);

        return $page;
    }

    /**
     * Billing credits are credit LINES on existing invoices, not a separate ledger. Servana holds
     * no credit table, and this projection deliberately does not invent one.
     *
     * @return LengthAwarePaginator<int, SubscriptionInvoiceItem>
     */
    public function billingCredits(?int $merchantId, int $perPage = 25): LengthAwarePaginator
    {
        $query = SubscriptionInvoiceItem::query()
            ->with('invoice.merchant')
            ->where('amount_minor', '<', 0);

        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }

        /** @var LengthAwarePaginator<int, SubscriptionInvoiceItem> $page */
        $page = $query->orderByDesc('id')->paginate($perPage);

        return $page;
    }

    /** @return LengthAwarePaginator<int, BillingEscalationEvent> */
    public function escalations(?int $merchantId, int $perPage = 25): LengthAwarePaginator
    {
        $query = BillingEscalationEvent::query()->with('merchant');

        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }

        /** @var LengthAwarePaginator<int, BillingEscalationEvent> $page */
        $page = $query->orderByDesc('period_boundary')->orderByDesc('id')->paginate($perPage);

        return $page;
    }

    /**
     * @param  Builder<MerchantSubscription>  $query
     * @param  array<string,mixed>  $filters
     */
    private function applySubscriptionFilters(Builder $query, array $filters): void
    {
        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['billing_interval'] ?? null) !== null) {
            $query->where('billing_interval', $filters['billing_interval']);
        }

        if (($filters['merchant_id'] ?? null) !== null) {
            $query->where('merchant_id', $filters['merchant_id']);
        }

        if (($filters['plan_id'] ?? null) !== null) {
            $query->where('plan_id', $filters['plan_id']);
        }

        if (($filters['renewal_from'] ?? null) !== null) {
            $query->where('current_period_end', '>=', $filters['renewal_from']);
        }

        if (($filters['renewal_to'] ?? null) !== null) {
            $query->where('current_period_end', '<', $filters['renewal_to']);
        }

        if (($filters['trial_ends_from'] ?? null) !== null) {
            $query->where('trial_ends_at', '>=', $filters['trial_ends_from']);
        }

        if (($filters['trial_ends_to'] ?? null) !== null) {
            $query->where('trial_ends_at', '<', $filters['trial_ends_to']);
        }

        if (($filters['has_scheduled_plan_change'] ?? null) === true) {
            $query->whereHas('scheduledPlanChanges', static function (Builder $inner): void {
                $inner->where('status', 'pending');
            });
        }
    }
}
