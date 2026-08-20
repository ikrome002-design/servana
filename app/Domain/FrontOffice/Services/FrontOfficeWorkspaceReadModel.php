<?php

declare(strict_types=1);

namespace App\Domain\FrontOffice\Services;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Receipts\Models\Receipt;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * UI-13 read-only Front Office presentation over shipped branch-owned facts.
 *
 * Counts are calculated by the database over the complete assigned-branch scope;
 * the browser never invents dashboard totals from page-one collection responses.
 */
final class FrontOfficeWorkspaceReadModel
{
    public const SUBSCRIPTION_GATE_REASON = 'External Gate W is closed. Phase 20D-W has no Wallet payment or billing-recovery runtime.';

    public const NOTIFICATION_GATE_REASON = 'Phase 21N has no in-app notification persistence, read or preference runtime.';

    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        $branch = $this->branch();
        [$dayStart, $dayEnd] = $this->nairobiDayWindow();

        $todayAppointments = Appointment::query()
            ->where('branch_id', $branch->id)
            ->whereBetween('starts_at', [$dayStart, $dayEnd]);
        $activeQueue = QueueEntry::query()
            ->where('branch_id', $branch->id)
            ->whereIn('status', QueueEntry::statusValues(QueueEntryStatus::activeStatuses()));
        $todaySessions = ServiceSession::query()
            ->where('branch_id', $branch->id)
            ->where(static function (Builder $sessions) use ($dayStart, $dayEnd): void {
                $sessions
                    ->whereBetween('started_at', [$dayStart, $dayEnd])
                    ->orWhereBetween('completed_at', [$dayStart, $dayEnd]);
            });
        $paymentGroups = PaymentRecordingGroup::query()->where('branch_id', $branch->id);

        $appointmentCounts = $this->statusCounts(clone $todayAppointments);
        $queueCounts = $this->statusCounts(clone $activeQueue);
        $sessionCounts = $this->statusCounts(clone $todaySessions);
        $invoiceCounts = $this->statusCounts(Invoice::query()->where('branch_id', $branch->id));
        $paymentCounts = $this->statusCounts(clone $paymentGroups);

        $pendingPayments = ($paymentCounts[PaymentRecordingGroupStatus::PendingValidation->value] ?? 0)
            + ($paymentCounts[PaymentRecordingGroupStatus::CorrectionRequired->value] ?? 0);
        $waiting = ($queueCounts[QueueEntryStatus::Waiting->value] ?? 0)
            + ($queueCounts[QueueEntryStatus::Assigned->value] ?? 0)
            + ($queueCounts[QueueEntryStatus::Called->value] ?? 0);

        return [
            'observed_at' => now()->toIso8601String(),
            'business_date' => now('Africa/Nairobi')->toDateString(),
            'branch' => [
                'id' => $branch->ulid,
                'name' => $branch->name,
                'code' => $branch->code,
                'town' => $branch->town,
            ],
            'appointments' => [
                'today' => (clone $todayAppointments)->count(),
                'by_status' => $appointmentCounts,
                'arrivals' => ($appointmentCounts[AppointmentStatus::CheckedIn->value] ?? 0)
                    + ($appointmentCounts[AppointmentStatus::Queued->value] ?? 0),
            ],
            'queue' => [
                'active' => (clone $activeQueue)->count(),
                'waiting' => $waiting,
                'in_service' => $queueCounts[QueueEntryStatus::InService->value] ?? 0,
                'by_status' => $queueCounts,
                'longest_estimated_wait_minutes' => (int) ((clone $activeQueue)
                    ->selectRaw('MAX(COALESCE(estimated_wait_override_minutes, estimated_wait_minutes)) AS aggregate')
                    ->value('aggregate') ?? 0),
            ],
            'sessions' => [
                'today' => (clone $todaySessions)->count(),
                'in_progress' => $sessionCounts[ServiceSessionStatus::InProgress->value] ?? 0,
                'completed' => $sessionCounts[ServiceSessionStatus::Completed->value] ?? 0,
                'by_status' => $sessionCounts,
            ],
            'invoices' => [
                'drafts' => $invoiceCounts[InvoiceStatus::Draft->value] ?? 0,
                'awaiting_payment' => ($invoiceCounts[InvoiceStatus::Issued->value] ?? 0)
                    + ($invoiceCounts[InvoiceStatus::PartiallyPaid->value] ?? 0),
                'by_status' => $invoiceCounts,
            ],
            'payments' => [
                'pending_validation' => $pendingPayments,
                'by_status' => $paymentCounts,
                'receipts_ready_today' => Receipt::query()
                    ->where('branch_id', $branch->id)
                    ->whereNull('reissue_of_receipt_id')
                    ->where('file_generation_status', 'ready')
                    ->whereBetween('created_at', [$dayStart, $dayEnd])
                    ->count(),
            ],
            'tasks' => [
                ['key' => 'arrivals', 'label' => 'Checked-in arrivals', 'count' => $appointmentCounts[AppointmentStatus::CheckedIn->value] ?? 0, 'route_name' => 'front-office.appointments'],
                ['key' => 'waiting', 'label' => 'Clients waiting in queue', 'count' => $waiting, 'route_name' => 'front-office.queue'],
                ['key' => 'invoice-drafts', 'label' => 'Invoice drafts to finish', 'count' => $invoiceCounts[InvoiceStatus::Draft->value] ?? 0, 'route_name' => 'front-office.invoices'],
                ['key' => 'payment-follow-up', 'label' => 'Recorded payments awaiting Finance', 'count' => $pendingPayments, 'route_name' => 'front-office.payments-status'],
            ],
            'get_started' => [
                'client_created' => Client::query()->where('branch_id', $branch->id)->exists(),
                'appointment_created' => Appointment::query()->where('branch_id', $branch->id)->exists(),
                'queue_used' => QueueEntry::query()->where('branch_id', $branch->id)->exists(),
                'session_completed' => ServiceSession::query()
                    ->where('branch_id', $branch->id)
                    ->where('status', ServiceSessionStatus::Completed->value)
                    ->exists(),
                'invoice_created' => Invoice::query()->where('branch_id', $branch->id)->exists(),
                'payment_recorded' => (clone $paymentGroups)->exists(),
                'receipt_available' => Receipt::query()
                    ->where('branch_id', $branch->id)
                    ->whereNull('reissue_of_receipt_id')
                    ->exists(),
            ],
            'subscription' => ['available' => false, 'reason' => self::SUBSCRIPTION_GATE_REASON],
            'notifications' => ['available' => false, 'reason' => self::NOTIFICATION_GATE_REASON],
        ];
    }

    public function branch(): MerchantBranch
    {
        $branchId = $this->context->branchIds()[0] ?? null;
        abort_if($branchId === null, 403);

        return MerchantBranch::query()
            ->where('merchant_id', $this->context->merchantId())
            ->findOrFail($branchId);
    }

    /** @return array{Carbon, Carbon} */
    private function nairobiDayWindow(): array
    {
        $today = now('Africa/Nairobi');

        return [
            $today->copy()->startOfDay()->utc(),
            $today->copy()->endOfDay()->utc(),
        ];
    }

    /**
     * @param Builder<*> $query
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
