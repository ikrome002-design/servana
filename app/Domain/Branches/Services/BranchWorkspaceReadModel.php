<?php

declare(strict_types=1);

namespace App\Domain\Branches\Services;

use App\Domain\Billing\Enums\SubscriptionInvoiceStatus;
use App\Domain\Billing\Models\SubscriptionInvoice;
use App\Domain\Branches\Enums\BranchDayStatus;
use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Enums\ServiceStatus;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Receipts\Models\Receipt;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\QueueEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Truthful assigned-branch operational composition for UI-10. Every value comes
 * from an authoritative shipped table; Gate W/21N capabilities are explicit and
 * no unavailable metric is represented as zero.
 */
final class BranchWorkspaceReadModel
{
    private const WALLET_GATE_REASON = 'External Gate W — Wallet by Citrus collections readiness';

    private const REPORTING_GATE_REASON = 'Phase 21N reporting runtime is not implemented';

    public function __construct(private readonly BranchClosureGuard $closureGuard) {}

    /** @return array<string, mixed> */
    public function forBranch(MerchantBranch $branch): array
    {
        $timezone = (string) config('servana.scheduling.business_timezone', 'Africa/Nairobi');
        $now = CarbonImmutable::now($timezone);
        $businessDate = $now->toDateString();
        $dayStart = $now->startOfDay();
        $dayEnd = $dayStart->addDay();

        $day = BranchDayRecord::query()
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $businessDate)
            ->first();
        $cashUp = BranchCashUp::query()
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $businessDate)
            ->latest('id')
            ->first();

        $serviceCounts = $this->statusCounts(Service::query()->where('branch_id', $branch->id));
        $queueCounts = $this->statusCounts(QueueEntry::query()->where('branch_id', $branch->id));
        $appointmentCounts = $this->statusCounts(
            Appointment::query()
                ->where('branch_id', $branch->id)
                ->where('starts_at', '>=', $dayStart->utc())
                ->where('starts_at', '<', $dayEnd->utc()),
        );
        $invoiceCounts = $this->statusCounts(Invoice::query()->where('branch_id', $branch->id));

        $validatedRevenue = Invoice::query()
            ->where('branch_id', $branch->id)
            ->where('validated_paid_minor', '>', 0)
            ->selectRaw('currency, SUM(validated_paid_minor) AS amount_minor')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(static fn (Invoice $invoice): array => [
                'currency' => $invoice->currency,
                'amount_minor' => (int) $invoice->getAttribute('amount_minor'),
            ])
            ->values()
            ->all();

        $nextSubscriptionInvoice = SubscriptionInvoice::query()
            ->whereIn('status', [
                SubscriptionInvoiceStatus::Issued->value,
                SubscriptionInvoiceStatus::PendingPayment->value,
                SubscriptionInvoiceStatus::PartiallyPaid->value,
                SubscriptionInvoiceStatus::Overdue->value,
                SubscriptionInvoiceStatus::PaymentFailed->value,
                SubscriptionInvoiceStatus::ReconciliationRequired->value,
            ])
            ->where('balance_minor', '>', 0)
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderBy('id')
            ->first();

        $activeServices = $serviceCounts[ServiceStatus::Active->value] ?? 0;
        $activeStaff = StaffProfile::query()
            ->where('primary_branch_id', $branch->id)
            ->where('is_active', true)
            ->count();

        return [
            'branch' => [
                'id' => $branch->ulid,
                'name' => $branch->name,
                'code' => $branch->code,
                'address' => $branch->address,
                'town' => $branch->town,
                'phone' => $branch->phone,
                'email' => $branch->email,
                'business_category' => $branch->business_category,
                'status' => $branch->status->value,
                'status_reason' => $branch->status_reason,
                'archived_at' => $branch->archived_at?->toIso8601String(),
            ],
            'business_date' => $businessDate,
            'day' => [
                'id' => $day?->ulid,
                'status' => $day?->status->value ?? BranchDayStatus::NotOpened->value,
                'opened_at' => $day?->opened_at?->toIso8601String(),
                'closed_at' => $day?->closed_at?->toIso8601String(),
                'queue_is_open' => $day?->effectiveQueueOpen() ?? false,
                'close_blockers' => $this->closureGuard->dayCloseBlockers($branch, $businessDate),
                'financial_close_blockers' => $this->closureGuard->financialDayCloseBlockers($branch, $businessDate),
            ],
            'services' => [
                'total' => array_sum($serviceCounts),
                'active' => $activeServices,
                'archived' => $serviceCounts[ServiceStatus::Archived->value] ?? 0,
            ],
            'staff' => ['active' => $activeStaff],
            'queue' => [
                'total' => array_sum($queueCounts),
                'active' => array_sum(array_intersect_key($queueCounts, array_flip(array_map(
                    static fn (QueueEntryStatus $status): string => $status->value,
                    QueueEntryStatus::activeStatuses(),
                )))),
                'by_status' => $queueCounts,
            ],
            'appointments' => [
                'today' => array_sum($appointmentCounts),
                'active_today' => array_sum(array_intersect_key($appointmentCounts, array_flip(array_map(
                    static fn (AppointmentStatus $status): string => $status->value,
                    AppointmentStatus::reservingStatuses(),
                )))),
                'by_status' => $appointmentCounts,
            ],
            'financial' => [
                'invoices_total' => array_sum($invoiceCounts),
                'invoices_by_status' => $invoiceCounts,
                'invoices_with_balance' => Invoice::query()
                    ->where('branch_id', $branch->id)
                    ->whereColumn('validated_paid_minor', '<', 'total_minor')
                    ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Voided->value])
                    ->count(),
                'pending_payment_validations' => PaymentRecordingGroup::query()
                    ->where('branch_id', $branch->id)
                    ->where('status', PaymentRecordingGroupStatus::PendingValidation->value)
                    ->count(),
                'receipts_issued_today' => Receipt::query()
                    ->where('branch_id', $branch->id)
                    ->where('created_at', '>=', $dayStart->utc())
                    ->where('created_at', '<', $dayEnd->utc())
                    ->count(),
                'validated_revenue_by_currency' => $validatedRevenue,
            ],
            'cash_up' => $cashUp === null ? null : [
                'id' => $cashUp->ulid,
                'status' => $cashUp->status->value,
                'currency' => 'KES',
                'expected_minor' => $cashUp->expected_minor,
                'counted_minor' => $cashUp->counted_minor,
                'variance_minor' => $cashUp->variance_minor,
            ],
            'billing' => [
                'status' => $branch->merchant->billing_status->value,
                'next_invoice' => $nextSubscriptionInvoice === null ? null : [
                    'id' => $nextSubscriptionInvoice->ulid,
                    'invoice_number' => $nextSubscriptionInvoice->invoice_number,
                    'status' => $nextSubscriptionInvoice->status->value,
                    'balance_minor' => $nextSubscriptionInvoice->balance_minor,
                    'currency' => $nextSubscriptionInvoice->currency,
                    'due_at' => $nextSubscriptionInvoice->due_at?->toDateString(),
                ],
                'payment_runtime' => ['available' => false, 'reason' => self::WALLET_GATE_REASON],
            ],
            'reporting' => ['available' => false, 'reason' => self::REPORTING_GATE_REASON],
            'notifications' => ['available' => false, 'reason' => self::WALLET_GATE_REASON],
            'get_started' => [
                'profile_complete' => $branch->address !== null && $branch->town !== null && $branch->phone !== null,
                'calendar_configured' => $branch->operatingHours()->exists(),
                'service_catalogue_ready' => $activeServices > 0,
                'staff_ready' => $activeStaff > 0,
                'day_opened' => $day?->status->isLive() ?? false,
                'cash_up_prepared' => $cashUp !== null,
                'reports' => ['available' => false, 'reason' => self::REPORTING_GATE_REASON],
            ],
        ];
    }

    /**
     * @param  Builder<*>  $query
     * @return array<string, int>
     */
    private function statusCounts(Builder $query): array
    {
        /** @var array<string, int> $counts */
        $counts = $query
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        ksort($counts);

        return $counts;
    }
}
