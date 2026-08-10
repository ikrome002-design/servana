<?php

declare(strict_types=1);

namespace App\Domain\Platform\Queries;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\MerchantBillingStatus;
use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantStatus;
use App\Domain\Merchants\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Super Administrator governance dashboard read projection (Phase UI-08, contract page §5.4.1).
 *
 * ## Why this exists at all
 *
 * The dashboard needs platform-wide TOTALS. Every shipped platform read is a paginated list, so a
 * browser aggregating page 1 would report a merchant count of 25 on a platform with 900 merchants
 * — a false figure on the screen the platform owner uses to govern. `docs/frontend/audits/ui-08/
 * page-readiness-matrix.json` records that as the proven reason this operation is authorized.
 *
 * ## What it is not
 *
 * READ-ONLY BY CONSTRUCTION: only SELECT queries. No table, no migration, no state machine, no
 * financial calculation, no new permission key. It reuses `platform.merchant.view`, the key the
 * shipped merchant-governance reads already require.
 *
 * ## Availability is a first-class value
 *
 * Wallet, reconciliation and integration-health series do not exist while External Gate W is
 * closed. Every section therefore reports an `availability` of `available` or `disabled_by_gate`
 * with the exact gate. A closed gate NEVER yields `0`, and never yields "healthy": on a governance
 * screen, a fabricated zero is indistinguishable from a real one, and reads as good news.
 *
 * ## Tenancy
 *
 * `MerchantScope` filters only when a merchant is resolved, and `ResolvePlatformContext` binds
 * none, so these tenant-owned models read across merchants naturally. No `withoutTenancy()` escape
 * hatch is used and tenant isolation is untouched for every other caller.
 *
 * ## Why the shapes below are spelled out
 *
 * The OpenAPI generator infers the response schema from the STATIC type. An `array<string,mixed>`
 * carries no shape, so it degraded every section to `string` and produced a contract no generated
 * client could use — the same failure mode as UI08-API-001, one level deeper. These named shapes
 * are the contract; they are what makes the published schema and the TypeScript types correct.
 *
 * @phpstan-type DashboardLifecycle array{
 *     availability:string, gate:string|null, as_of:string, total_merchants:int,
 *     by_operational_status:array{pending_setup:int,active:int,suspended:int,deactivated:int},
 *     by_billing_status:array<string,int>, billing_suspended:int, active_branches:int,
 *     definitions:array<string,string>, time_range:string, drill_through:string
 * }
 * @phpstan-type DashboardCommercial array{
 *     availability:string, gate:string|null, as_of:string, invoices_by_status:array<string,int>,
 *     issued_invoices:int, open_invoice_balance_minor:int,
 *     definitions:array<string,string>, time_range:string, drill_through:string
 * }
 * @phpstan-type DashboardRegistrations array{
 *     availability:string, gate:string|null, as_of:string, registered_last_7_days:int,
 *     registered_last_30_days:int, awaiting_setup_completion:int,
 *     definitions:array<string,string>, time_range:string, drill_through:string
 * }
 * @phpstan-type DashboardTasks array{
 *     availability:string, gate:string|null, merchants_suspended_for_billing:int,
 *     merchants_suspended_by_policy:int, overdue_invoices:int,
 *     definitions:array<string,string>, time_range:string, drill_through:string
 * }
 * @phpstan-type DashboardAudit array{
 *     availability:string, gate:string|null, as_of:string, events_last_7_days:int,
 *     by_severity:array<string,int>, definitions:array<string,string>, time_range:string,
 *     drill_through:string
 * }
 * @phpstan-type DashboardIntegrations array{
 *     availability:string, gate:string, gate_statement:string, wallet:null,
 *     reconciliation_exceptions:null, refer_and_earn:null,
 *     definitions:array<string,string>, time_range:null, drill_through:null
 * }
 * @phpstan-type DashboardSummary array{
 *     as_of:string, merchant_lifecycle:DashboardLifecycle, commercial:DashboardCommercial,
 *     registration_monitoring:DashboardRegistrations, governance_tasks:DashboardTasks,
 *     audit_alerts:DashboardAudit, integrations:DashboardIntegrations
 * }
 */
final class PlatformDashboardProjection
{
    /** The gate that blocks every Wallet-derived series. */
    public const EXTERNAL_GATE_W = 'external_gate_w';

    private const GATE_W_STATEMENT = 'External Gate W (Wallet by Citrus collections readiness, Plan §80.2) is closed. Servana holds no Wallet-confirmed money-movement records, so this figure has no source and is not reported as zero.';

    /**
     * @return DashboardSummary
     */
    public function summary(?CarbonImmutable $now = null): array
    {
        $instant = $now ?? CarbonImmutable::now();

        return [
            'as_of' => $instant->toIso8601String(),
            'merchant_lifecycle' => $this->merchantLifecycle($instant),
            'commercial' => $this->commercial($instant),
            'registration_monitoring' => $this->registrationMonitoring($instant),
            'governance_tasks' => $this->governanceTasks(),
            'audit_alerts' => $this->auditAlerts($instant),
            'integrations' => $this->integrations(),
        ];
    }

    /**
     * Merchant counts by operational status, plus billing-suspended and total active branches.
     *
     * Operational status and billing status are counted SEPARATELY and never summed: a merchant
     * suspended for billing is a different governance situation from one suspended by policy, and
     * conflating them is the exact defect the merchant screens are required to avoid.
     *
     * @return DashboardLifecycle
     */
    private function merchantLifecycle(CarbonImmutable $instant): array
    {
        /** @var array<string,int> $byStatus */
        $byStatus = DB::table('merchants')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn ($total): int => (int) $total)
            ->all();

        /** @var array<string,int> $byBillingStatus */
        $byBillingStatus = DB::table('merchants')
            ->selectRaw('billing_status, count(*) as total')
            ->groupBy('billing_status')
            ->pluck('total', 'billing_status')
            ->map(static fn ($total): int => (int) $total)
            ->all();

        $count = static fn (array $map, string $key): int => $map[$key] ?? 0;

        return [
            'availability' => 'available',
            'gate' => null,
            'as_of' => $instant->toIso8601String(),
            'total_merchants' => Merchant::query()->count(),
            'by_operational_status' => [
                'pending_setup' => $count($byStatus, MerchantStatus::PendingSetup->value),
                'active' => $count($byStatus, MerchantStatus::Active->value),
                'suspended' => $count($byStatus, MerchantStatus::Suspended->value),
                'deactivated' => $count($byStatus, MerchantStatus::Deactivated->value),
            ],
            'by_billing_status' => $byBillingStatus,
            'billing_suspended' => $count($byBillingStatus, MerchantBillingStatus::SuspendedBilling->value),
            'active_branches' => MerchantBranch::query()->count(),
            'definitions' => [
                'total_merchants' => 'Every self-registered merchant record, in any lifecycle state.',
                'by_operational_status' => 'Merchant.status — the platform-governance lifecycle. Never merged with billing status.',
                'by_billing_status' => 'Merchant.billing_status — the subscription-billing lifecycle. A billing suspension is not a policy suspension.',
                'active_branches' => 'Branch records across every merchant.',
            ],
            'time_range' => 'Point-in-time counts as of the instant shown.',
            'drill_through' => 'platform.merchants',
        ];
    }

    /**
     * Issued-invoice state and the outstanding balance.
     *
     * The balance is summed from the STORED invoice snapshots in integer minor units. Nothing here
     * recalculates an invoice: an issued invoice is immutable, and a dashboard that recomputed one
     * could disagree with the invoice the merchant was actually sent.
     *
     * @return DashboardCommercial
     */
    private function commercial(CarbonImmutable $instant): array
    {
        /** @var array<string,int> $invoicesByStatus */
        $invoicesByStatus = DB::table('subscription_invoices')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn ($total): int => (int) $total)
            ->all();

        $openStatuses = [
            SubscriptionInvoiceStatus::Issued->value,
            SubscriptionInvoiceStatus::PendingPayment->value,
            SubscriptionInvoiceStatus::PartiallyPaid->value,
            SubscriptionInvoiceStatus::Overdue->value,
        ];

        $openBalanceMinor = (int) DB::table('subscription_invoices')
            ->whereIn('status', $openStatuses)
            ->sum('balance_minor');

        return [
            'availability' => 'available',
            'gate' => null,
            'as_of' => $instant->toIso8601String(),
            'invoices_by_status' => $invoicesByStatus,
            'issued_invoices' => SubscriptionInvoice::query()->count(),
            'open_invoice_balance_minor' => $openBalanceMinor,
            'definitions' => [
                'invoices_by_status' => 'Issued platform subscription invoices grouped by their stored status.',
                'open_invoice_balance_minor' => 'Sum of `balance_minor` on issued, pending-payment, partially-paid and overdue invoices, in integer minor units. Read from the stored snapshot; never recalculated.',
            ],
            'time_range' => 'Point-in-time totals as of the instant shown.',
            'drill_through' => 'platform.billing-subscriptions',
        ];
    }

    /**
     * Registration volume and the setup-completion gap.
     *
     * No risk SCORE is invented. Servana records no fraud signal, so this reports what it actually
     * has — volume, recency and how many registrations never finished setup — rather than a
     * confidence number with nothing behind it.
     *
     * @return DashboardRegistrations
     */
    private function registrationMonitoring(CarbonImmutable $instant): array
    {
        $thirtyDaysAgo = $instant->subDays(30);
        $sevenDaysAgo = $instant->subDays(7);

        return [
            'availability' => 'available',
            'gate' => null,
            'as_of' => $instant->toIso8601String(),
            'registered_last_7_days' => Merchant::query()->where('created_at', '>=', $sevenDaysAgo)->count(),
            'registered_last_30_days' => Merchant::query()->where('created_at', '>=', $thirtyDaysAgo)->count(),
            'awaiting_setup_completion' => Merchant::query()
                ->where('status', MerchantStatus::PendingSetup->value)
                ->count(),
            'definitions' => [
                'registered_last_7_days' => 'Merchant records created in the 7 days before the instant shown.',
                'registered_last_30_days' => 'Merchant records created in the 30 days before the instant shown.',
                'awaiting_setup_completion' => 'Merchants still in pending_setup. Self-registration is the only creation path; this is not an approval queue.',
            ],
            'time_range' => 'Rolling 7-day and 30-day windows ending at the instant shown.',
            'drill_through' => 'platform.merchant-registrations',
        ];
    }

    /**
     * Work genuinely waiting for a platform owner.
     *
     * Only items with a real source appear. Reconciliation exceptions are deliberately ABSENT
     * rather than zero — they are Gate-W blocked and reported in the integrations section.
     *
     * @return DashboardTasks
     */
    private function governanceTasks(): array
    {
        return [
            'availability' => 'available',
            'gate' => null,
            'merchants_suspended_for_billing' => Merchant::query()
                ->where('billing_status', MerchantBillingStatus::SuspendedBilling->value)
                ->count(),
            'merchants_suspended_by_policy' => Merchant::query()
                ->where('status', MerchantStatus::Suspended->value)
                ->count(),
            'overdue_invoices' => SubscriptionInvoice::query()
                ->where('status', SubscriptionInvoiceStatus::Overdue->value)
                ->count(),
            'definitions' => [
                'merchants_suspended_for_billing' => 'Merchants whose billing status is suspended_billing. Clearing the balance is the recovery path.',
                'merchants_suspended_by_policy' => 'Merchants suspended operationally by platform governance. A billing payment never clears this.',
                'overdue_invoices' => 'Issued invoices whose stored status is overdue.',
            ],
            'time_range' => 'Current state.',
            'drill_through' => 'platform.merchants',
        ];
    }

    /**
     * Recent append-only audit volume and the highest severities present.
     *
     * @return DashboardAudit
     */
    private function auditAlerts(CarbonImmutable $instant): array
    {
        $since = $instant->subDays(7);

        /** @var array<string,int> $bySeverity */
        $bySeverity = DB::table('audit_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('severity, count(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity')
            ->map(static fn ($total): int => (int) $total)
            ->all();

        return [
            'availability' => 'available',
            'gate' => null,
            'as_of' => $instant->toIso8601String(),
            'events_last_7_days' => AuditLog::query()->where('created_at', '>=', $since)->count(),
            'by_severity' => $bySeverity,
            'definitions' => [
                'events_last_7_days' => 'Append-only audit events recorded in the 7 days before the instant shown.',
                'by_severity' => 'Those events grouped by their recorded severity.',
            ],
            'time_range' => 'Rolling 7-day window ending at the instant shown.',
            'drill_through' => 'platform.audit',
        ];
    }

    /**
     * Integration health — truthfully unavailable.
     *
     * Wallet client health, circuit-breaker state, webhook lag, reconciliation exceptions,
     * allocation drift and Refer & Earn qualification runs all require External Gate W. Reporting
     * them as `0`, or as "healthy", would tell the platform owner that a system they cannot even
     * reach is working.
     *
     * @return DashboardIntegrations
     */
    private function integrations(): array
    {
        return [
            'availability' => 'disabled_by_gate',
            'gate' => self::EXTERNAL_GATE_W,
            'gate_statement' => self::GATE_W_STATEMENT,
            'wallet' => null,
            'reconciliation_exceptions' => null,
            'refer_and_earn' => null,
            'definitions' => [
                'wallet' => 'Unavailable: no Wallet client telemetry exists while External Gate W is closed.',
                'reconciliation_exceptions' => 'Unavailable: Servana holds no Wallet-confirmed payments to reconcile.',
                'refer_and_earn' => 'Unavailable: Phase 21R-B qualification runs are blocked behind the same gate.',
            ],
            'time_range' => null,
            'drill_through' => null,
        ];
    }
}
