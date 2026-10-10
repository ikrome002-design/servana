<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Services;

use App\Domain\Clients\Enums\ConsentChannel;
use App\Domain\Clients\Models\Client;
use App\Domain\Compensation\Enums\EarningsQueryStatus;
use App\Domain\Compensation\Models\CommissionLedgerEntry;
use App\Domain\Compensation\Models\EarningsQuery;
use App\Domain\Compensation\Models\PersonnelPayoutItem;
use App\Domain\Compensation\Models\SalaryLedgerEntry;
use App\Domain\Compensation\Services\PersonnelEarningsReadModel;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Support\PhoneNumberDisplayMasker;
use App\Domain\Messaging\Sms\Support\ServedClientSelector;
use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Scheduling\Services\PersonnelAvailabilityReadModel;
use App\Http\Api\ApiPagination;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Phase UI-14 Personnel experience projection.
 *
 * Every entry point requires an explicit StaffProfile resolved from the authenticated membership.
 * The read model accepts no staff identifier from a request and every query repeats the own-profile
 * constraint. It only composes existing appointment, queue, session, client-consent, SMS,
 * compensation, payout, query and HR-controlled availability facts. It creates no business fact,
 * recalculates no money and exposes no full contact.
 */
final class PersonnelExperienceReadModel
{
    public function __construct(
        private readonly PersonnelEarningsReadModel $earnings,
        private readonly PersonnelAvailabilityReadModel $availability,
        private readonly ServedClientSelector $servedClients,
    ) {}

    /** @return array<string, mixed> */
    public function dashboard(StaffProfile $staff): array
    {
        $timezone = (string) config('servana.scheduling.business_timezone', 'Africa/Nairobi');
        $now = CarbonImmutable::now($timezone);
        $dayStart = $now->startOfDay();
        $dayEnd = $dayStart->addDay();

        $activeQueue = QueueEntry::query()
            ->where('staff_profile_id', $staff->id)
            ->whereIn('status', QueueEntry::statusValues(QueueEntryStatus::activeStatuses()));
        $nextQueue = (clone $activeQueue)->with(['service', 'client'])->orderBy('position')->first();

        $todayAppointments = Appointment::query()
            ->where('assigned_personnel_staff_profile_id', $staff->id)
            ->where('starts_at', '>=', $dayStart)
            ->where('starts_at', '<', $dayEnd);
        $nextAppointment = Appointment::query()
            ->where('assigned_personnel_staff_profile_id', $staff->id)
            ->where('starts_at', '>=', $now)
            ->whereNotIn('status', [
                AppointmentStatus::Cancelled->value,
                AppointmentStatus::CancelledWithReason->value,
                AppointmentStatus::NoShow->value,
            ])
            ->with(['service', 'client'])
            ->orderBy('starts_at')
            ->first();

        $activeSession = ServiceSession::query()
            ->where('staff_profile_id', $staff->id)
            ->whereIn('status', ServiceSessionStatus::values(ServiceSessionStatus::activeStatuses()))
            ->with(['service', 'client'])
            ->latest('started_at')
            ->first();

        $latestPayout = PersonnelPayoutItem::query()
            ->where('staff_profile_id', $staff->id)
            ->with('payoutRun')
            ->latest('id')
            ->first();

        $staff->loadMissing('primaryBranch');

        return [
            'observed_at' => now()->toIso8601String(),
            'business_date' => $now->toDateString(),
            'staff' => [
                'id' => $staff->ulid,
                'display_name' => $staff->display_name,
                'role_title' => $staff->role_title,
                'branch' => $staff->primaryBranch === null ? null : [
                    'id' => $staff->primaryBranch->ulid,
                    'name' => $staff->primaryBranch->name,
                    'code' => $staff->primaryBranch->code,
                ],
            ],
            'next_assignment' => $this->nextAssignment($nextQueue, $nextAppointment, $activeSession),
            'queue' => [
                'active' => (clone $activeQueue)->count(),
                'next_position' => $nextQueue?->position,
                'estimated_wait_minutes' => $nextQueue?->effectiveWaitMinutes(),
            ],
            'appointments' => [
                'today' => (clone $todayAppointments)->count(),
                'upcoming' => Appointment::query()
                    ->where('assigned_personnel_staff_profile_id', $staff->id)
                    ->where('starts_at', '>=', $now)
                    ->count(),
                'next_at' => $nextAppointment?->starts_at->toIso8601String(),
            ],
            'sessions' => [
                'active' => ServiceSession::query()->where('staff_profile_id', $staff->id)->active()->count(),
                'completed_today' => ServiceSession::query()
                    ->where('staff_profile_id', $staff->id)
                    ->where('status', ServiceSessionStatus::Completed->value)
                    ->where('completed_at', '>=', $dayStart)
                    ->where('completed_at', '<', $dayEnd)
                    ->count(),
            ],
            'preferred_requests' => [
                'active' => QueueEntry::query()
                    ->where('preferred_personnel_staff_profile_id', $staff->id)
                    ->whereIn('status', QueueEntry::statusValues(QueueEntryStatus::activeStatuses()))
                    ->count(),
            ],
            'clients' => [
                'served' => $this->servedClients->query($staff)->count(),
                'can_compose_sms' => false,
                'recent_messages' => PersonnelSmsCampaign::query()->where('staff_profile_id', $staff->id)->count(),
            ],
            'availability' => $this->availability->forStaff($staff, false),
            'earnings' => [
                'tab_visibility' => $this->earnings->tabVisibility($staff),
                'currencies' => $this->earnings->overview($staff),
                'terms' => $this->earnings->compensationTerms($staff),
                'latest_payout' => $latestPayout === null ? null : [
                    'id' => $latestPayout->ulid,
                    'currency' => $latestPayout->currency,
                    'gross_amount_minor' => $latestPayout->gross_amount_minor,
                    'status' => $latestPayout->status->value,
                    'period_start' => $latestPayout->payoutRun?->period_start?->toDateString(),
                    'period_end' => $latestPayout->payoutRun?->period_end?->toDateString(),
                ],
                'unresolved_queries' => EarningsQuery::query()
                    ->where('staff_profile_id', $staff->id)
                    ->whereIn('status', [EarningsQueryStatus::Open->value, EarningsQueryStatus::Assigned->value])
                    ->count(),
            ],
        ];
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ServiceSession>
     */
    public function serviceHistory(StaffProfile $staff, array $filters): LengthAwarePaginator
    {
        $query = $this->serviceHistoryQuery($staff, $filters)
            ->with(['service', 'client'])
            ->addSelect([
                'commission_ulid' => CommissionLedgerEntry::query()
                    ->select('ulid')
                    ->whereColumn('service_session_id', 'service_sessions.id')
                    ->latest('id')
                    ->limit(1),
                'commission_status' => CommissionLedgerEntry::query()
                    ->select('status')
                    ->whereColumn('service_session_id', 'service_sessions.id')
                    ->latest('id')
                    ->limit(1),
            ]);

        ApiPagination::applySort($query, $filters['sort'] ?? null, '-completed_at');

        return $query->paginate(ApiPagination::perPage($filters))->withQueryString();
    }

    /** @param array<string, mixed> $filters
     * @return array<string, int>
     */
    public function serviceHistorySummary(StaffProfile $staff, array $filters): array
    {
        $query = $this->serviceHistoryQuery($staff, $filters);

        return [
            'records' => (clone $query)->count(),
            'completed' => (clone $query)->where('status', ServiceSessionStatus::Completed->value)->count(),
            'cancelled' => (clone $query)->where('status', ServiceSessionStatus::Cancelled->value)->count(),
            'clients_served' => (clone $query)
                ->where('status', ServiceSessionStatus::Completed->value)
                ->distinct('client_id')
                ->count('client_id'),
            'preferred_requests_honored' => (clone $query)
                ->where('preferred_personnel_honored', true)
                ->count(),
        ];
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function preferredRequests(StaffProfile $staff, array $filters): LengthAwarePaginator
    {
        $queue = QueueEntry::query()
            ->where('queue_entries.preferred_personnel_staff_profile_id', $staff->id)
            ->join('services', 'services.id', '=', 'queue_entries.service_id')
            ->join('clients', 'clients.id', '=', 'queue_entries.client_id')
            ->selectRaw("'queue' as source, queue_entries.ulid as id, queue_entries.status::text as status, queue_entries.queued_at as requested_at, queue_entries.position as position, queue_entries.staff_profile_id = ? as assigned_to_you, services.ulid as service_id, services.name as service_name, clients.ulid as client_id, clients.full_name as client_name, clients.phone_last_four", [$staff->id]);

        $appointments = Appointment::query()
            ->where('appointments.preferred_personnel_staff_profile_id', $staff->id)
            ->join('services', 'services.id', '=', 'appointments.service_id')
            ->join('clients', 'clients.id', '=', 'appointments.client_id')
            ->selectRaw("'appointment' as source, appointments.ulid as id, appointments.status::text as status, appointments.starts_at as requested_at, null::integer as position, appointments.assigned_personnel_staff_profile_id = ? as assigned_to_you, services.ulid as service_id, services.name as service_name, clients.ulid as client_id, clients.full_name as client_name, clients.phone_last_four", [$staff->id]);

        if (($filters['source'] ?? null) === 'queue') {
            $union = $queue;
        } elseif (($filters['source'] ?? null) === 'appointment') {
            $union = $appointments;
        } else {
            $union = $queue->unionAll($appointments);
        }

        $query = DB::query()->fromSub($union, 'preferred_requests');
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $query->orderByDesc('requested_at')->orderByDesc('id');

        /** @var ConcretePaginator<int, \stdClass> $paginator */
        $paginator = $query->paginate(ApiPagination::perPage($filters));
        $paginator->getCollection()->each(static function (\stdClass $row): void {
            $row->assigned_to_you = (bool) $row->assigned_to_you;
            $row->assignment_label = $row->assigned_to_you ? 'Confirmed assignment' : 'Request only or reassigned';
            $row->service = ['id' => $row->service_id, 'name' => $row->service_name];
            $row->client = [
                'id' => $row->client_id,
                'full_name' => $row->client_name,
                'phone_masked' => PhoneNumberDisplayMasker::maskFromLastFour((string) $row->phone_last_four),
            ];
            unset($row->service_id, $row->service_name, $row->client_id, $row->client_name, $row->phone_last_four);
        });

        return $paginator;
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Client>
     */
    public function servedClientContexts(StaffProfile $staff, array $filters): LengthAwarePaginator
    {
        $query = $this->servedClients->query($staff)->with([
            'consents' => fn ($consents) => $consents->where('channel', ConsentChannel::Sms->value),
        ]);

        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
            $query->where('full_name', 'ilike', '%'.$escaped.'%');
        }

        $query->addSelect([
            'visit_count' => ServiceSession::query()
                ->selectRaw('count(*)')
                ->whereColumn('client_id', 'clients.id')
                ->where('staff_profile_id', $staff->id)
                ->where('status', ServiceSessionStatus::Completed->value),
            'last_served_at' => ServiceSession::query()
                ->selectRaw('max(completed_at)')
                ->whereColumn('client_id', 'clients.id')
                ->where('staff_profile_id', $staff->id)
                ->where('status', ServiceSessionStatus::Completed->value),
        ]);

        $sort = (string) ($filters['sort'] ?? 'full_name');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $query->orderBy(ltrim($sort, '-'), $direction)->orderByDesc('clients.id');

        $paginator = $query->paginate(ApiPagination::perPage($filters))->withQueryString();
        $clients = $paginator->getCollection();
        $clientIds = array_values($clients->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $services = $this->servedServiceNames($staff, $clientIds);

        $clients->each(static function (Client $client) use ($services): void {
            $client->setAttribute('served_services', $services[$client->id] ?? []);
            $client->setAttribute('sms_consent_state', $client->consents->first()?->state->value ?? 'missing');
        });

        return $paginator;
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, CommissionLedgerEntry>
     */
    public function commissions(StaffProfile $staff, array $filters): LengthAwarePaginator
    {
        $query = CommissionLedgerEntry::query()
            ->where('staff_profile_id', $staff->id)
            ->with(['invoice.client', 'invoiceItem.service', 'invoiceItem.serviceSession']);

        $this->applyLedgerFilters($query, $filters, 'earned_at');
        $sort = in_array($filters['sort'] ?? null, ['earned_at', '-earned_at', 'created_at', '-created_at'], true)
            ? $filters['sort']
            : '-earned_at';
        ApiPagination::applySort($query, $sort, '-earned_at');

        return $query->paginate(ApiPagination::perPage($filters))->withQueryString();
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, SalaryLedgerEntry>
     */
    public function salary(StaffProfile $staff, array $filters): LengthAwarePaginator
    {
        $query = SalaryLedgerEntry::query()->where('staff_profile_id', $staff->id);
        $this->applyLedgerFilters($query, $filters, 'pay_period_start');
        $sort = in_array($filters['sort'] ?? null, ['pay_period_start', '-pay_period_start', 'pay_period_end', '-pay_period_end', 'created_at', '-created_at'], true)
            ? $filters['sort']
            : '-pay_period_start';
        ApiPagination::applySort($query, $sort, '-pay_period_start');

        return $query->paginate(ApiPagination::perPage($filters))->withQueryString();
    }

    /** @return array<string, mixed> */
    public function availability(StaffProfile $staff): array
    {
        return $this->availability->forStaff($staff, false);
    }

    /** @return array<string, mixed>|null */
    private function nextAssignment(?QueueEntry $queue, ?Appointment $appointment, ?ServiceSession $session): ?array
    {
        if ($session !== null) {
            $service = $session->service;

            return [
                'kind' => 'session', 'id' => $session->ulid, 'status' => $session->status->value,
                'title' => $service === null ? 'Service session' : $service->name,
                'client_name' => $session->client?->full_name,
                'phone_masked' => $session->client?->maskedPhone(),
                'at' => $session->started_at?->toIso8601String(),
                'route_name' => 'personnel.work-sessions',
            ];
        }

        if ($queue !== null) {
            $service = $queue->service;

            return [
                'kind' => 'queue', 'id' => $queue->ulid, 'status' => $queue->status->value,
                'title' => $service === null ? 'Queue assignment' : $service->name,
                'client_name' => $queue->client?->full_name,
                'phone_masked' => $queue->client?->maskedPhone(),
                'at' => $queue->queued_at->toIso8601String(),
                'position' => $queue->position,
                'estimated_wait_minutes' => $queue->effectiveWaitMinutes(),
                'route_name' => 'personnel.work-queue',
            ];
        }

        if ($appointment !== null) {
            $service = $appointment->service;

            return [
                'kind' => 'appointment', 'id' => $appointment->ulid, 'status' => $appointment->status->value,
                'title' => $service === null ? 'Appointment' : $service->name,
                'client_name' => $appointment->client?->full_name,
                'phone_masked' => $appointment->client?->maskedPhone(),
                'at' => $appointment->starts_at->toIso8601String(),
                'route_name' => 'personnel.work-appointments',
            ];
        }

        return null;
    }

    /** @param array<string, mixed> $filters
     * @return Builder<ServiceSession>
     */
    private function serviceHistoryQuery(StaffProfile $staff, array $filters): Builder
    {
        $query = ServiceSession::query()
            ->where('staff_profile_id', $staff->id)
            ->whereIn('status', [ServiceSessionStatus::Completed->value, ServiceSessionStatus::Cancelled->value]);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['date_from'])) {
            $query->whereDate('started_at', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('started_at', '<=', $filters['date_to']);
        }
        if (isset($filters['service'])) {
            $query->whereHas('service', fn (Builder $service) => $service->where('ulid', $filters['service']));
        }

        return $query;
    }

    /** @param list<int> $clientIds
     * @return array<int, list<array{id: string, name: string}>>
     */
    private function servedServiceNames(StaffProfile $staff, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $rows = ServiceSession::query()
            ->where('service_sessions.staff_profile_id', $staff->id)
            ->where('service_sessions.status', ServiceSessionStatus::Completed->value)
            ->whereIn('service_sessions.client_id', $clientIds)
            ->join('services', 'services.id', '=', 'service_sessions.service_id')
            ->select(['service_sessions.client_id', 'services.ulid', 'services.name'])
            ->distinct()
            ->orderBy('services.name')
            ->get();

        /** @var array<int, list<array{id: string, name: string}>> $result */
        $result = [];
        foreach ($rows as $row) {
            $values = $row->getAttributes();
            $result[(int) ($values['client_id'] ?? 0)][] = [
                'id' => (string) ($values['ulid'] ?? ''),
                'name' => (string) ($values['name'] ?? ''),
            ];
        }

        return $result;
    }

    /** @param Builder<CommissionLedgerEntry>|Builder<SalaryLedgerEntry> $query
     * @param  array<string, mixed>  $filters
     */
    private function applyLedgerFilters(Builder $query, array $filters, string $dateColumn): void
    {
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['date_from'])) {
            $query->whereDate($dateColumn, '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate($dateColumn, '<=', $filters['date_to']);
        }
    }
}
