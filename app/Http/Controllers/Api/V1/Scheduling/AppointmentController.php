<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scheduling;

use App\Domain\Catalogue\Enums\ServiceStatus;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Actions\AssignAppointment;
use App\Domain\Scheduling\Actions\CancelAppointment;
use App\Domain\Scheduling\Actions\CheckInAppointment;
use App\Domain\Scheduling\Actions\CreateAppointment;
use App\Domain\Scheduling\Actions\MarkAppointmentNoShow;
use App\Domain\Scheduling\Actions\RescheduleAppointment;
use App\Domain\Scheduling\Actions\TransferAppointment;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Concerns\ResolvesWriteBranch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\AppointmentIndexRequest;
use App\Http\Requests\Scheduling\AssignAppointmentRequest;
use App\Http\Requests\Scheduling\CancelAppointmentRequest;
use App\Http\Requests\Scheduling\RescheduleAppointmentRequest;
use App\Http\Requests\Scheduling\StoreAppointmentRequest;
use App\Http\Requests\Scheduling\TransferAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Appointments (Plan §36; Phase 16A). Front Office owns all mutations
 * (`appointment.*`); Branch Manager has branch-scoped READ-ONLY visibility via
 * `branch.dashboard.view` (AppointmentPolicy). Reads are branch-scoped
 * (BranchScope) and client contact is ALWAYS masked (AppointmentResource). Every
 * mutation delegates to a transactional domain action that re-authorizes, locks,
 * validates state + the shared scheduling gates, and writes one audit event. The
 * merchant/branch/end-time/status/actor are derived server-side; body-supplied
 * ownership identifiers are never honoured.
 */
final class AppointmentController extends Controller
{
    use ResolvesWriteBranch;

    private const RELATIONS = ['service', 'client', 'preferredPersonnel', 'assignedPersonnel'];

    public function __construct(private readonly TenantContext $context) {}

    public function index(AppointmentIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Appointment::class);

        $filters = $request->validated();

        // BranchScope + MerchantScope already restrict to the caller's branch(es)
        // and merchant; the filters below only narrow within that.
        $query = Appointment::query()->with(self::RELATIONS);

        $this->applyDateFilters($query, $filters);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['client'])) {
            $query->where('client_id', $this->scopedId(Client::query(), (string) $filters['client']));
        }
        if (isset($filters['service'])) {
            $query->where('service_id', $this->scopedId(Service::query(), (string) $filters['service']));
        }
        if (isset($filters['assigned_personnel'])) {
            $query->where('assigned_personnel_staff_profile_id', $this->scopedId(StaffProfile::query(), (string) $filters['assigned_personnel']));
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'starts_at');

        return AppointmentResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        $this->authorize('view', $appointment);

        return AppointmentResource::make($appointment->load(self::RELATIONS));
    }

    public function store(StoreAppointmentRequest $request, CreateAppointment $action): JsonResponse
    {
        $this->authorize('create', Appointment::class);

        $data = $request->validated();
        $branch = $this->resolveWriteBranch($this->context, $data['branch_id'] ?? null);

        $client = $this->branchModel(Client::query(), $branch->id, (string) $data['client']);
        $service = $this->branchModel(
            Service::query()->where('status', ServiceStatus::Active->value),
            $branch->id,
            (string) $data['service'],
        );
        $assigned = isset($data['assigned_personnel'])
            ? $this->branchModel(StaffProfile::query(), $branch->id, (string) $data['assigned_personnel'], 'primary_branch_id')
            : null;
        $preferred = isset($data['preferred_personnel'])
            ? $this->branchModel(StaffProfile::query(), $branch->id, (string) $data['preferred_personnel'], 'primary_branch_id')
            : null;

        /** @var User $actor */
        $actor = $request->user();

        $appointment = $action->handle(
            $branch,
            $actor,
            $client,
            $service,
            CarbonImmutable::parse($data['starts_at']),
            $assigned,
            $preferred,
        );

        return AppointmentResource::make($appointment->load(self::RELATIONS))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function assign(AssignAppointmentRequest $request, Appointment $appointment, AssignAppointment $action): AppointmentResource
    {
        $this->authorize('assign', $appointment);

        $staff = $this->branchModel(StaffProfile::query(), $appointment->branch_id, (string) $request->validated('personnel'), 'primary_branch_id');

        /** @var User $actor */
        $actor = $request->user();

        return AppointmentResource::make($action->handle($appointment, $actor, $staff)->load(self::RELATIONS));
    }

    public function transfer(TransferAppointmentRequest $request, Appointment $appointment, TransferAppointment $action): AppointmentResource
    {
        $this->authorize('transfer', $appointment);

        $data = $request->validated();
        $staff = $this->branchModel(StaffProfile::query(), $appointment->branch_id, (string) $data['personnel'], 'primary_branch_id');

        /** @var User $actor */
        $actor = $request->user();

        return AppointmentResource::make(
            $action->handle($appointment, $actor, $staff, $data['reason'] ?? null)->load(self::RELATIONS),
        );
    }

    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment, RescheduleAppointment $action): AppointmentResource
    {
        $this->authorize('reschedule', $appointment);

        /** @var User $actor */
        $actor = $request->user();

        return AppointmentResource::make(
            $action->handle($appointment, $actor, CarbonImmutable::parse($request->validated('starts_at')))->load(self::RELATIONS),
        );
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment, CancelAppointment $action): AppointmentResource
    {
        $this->authorize('cancel', $appointment);

        /** @var User $actor */
        $actor = $request->user();

        return AppointmentResource::make(
            $action->handle($appointment, $actor, $request->validated('reason'))->load(self::RELATIONS),
        );
    }

    public function checkIn(Appointment $appointment, CheckInAppointment $action): AppointmentResource
    {
        $this->authorize('checkIn', $appointment);

        /** @var User $actor */
        $actor = request()->user();

        return AppointmentResource::make($action->handle($appointment, $actor)->load(self::RELATIONS));
    }

    public function noShow(Appointment $appointment, MarkAppointmentNoShow $action): AppointmentResource
    {
        $this->authorize('markNoShow', $appointment);

        /** @var User $actor */
        $actor = request()->user();

        return AppointmentResource::make($action->handle($appointment, $actor)->load(self::RELATIONS));
    }

    /**
     * Resolve a model by ULID inside a specific branch (404 on foreign/out-of-branch).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    private function branchModel(Builder $query, int $branchId, string $ulid, string $branchColumn = 'branch_id')
    {
        return $query->where($branchColumn, $branchId)->where('ulid', $ulid)->firstOr(fn () => abort(404));
    }

    /**
     * Resolve a scoped ULID to its internal id for filtering, or 0 (no match,
     * no existence leak) when it is not visible to the caller.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function scopedId(Builder $query, string $ulid): int
    {
        return (int) ($query->where('ulid', $ulid)->value('id') ?? 0);
    }

    /**
     * Apply `date` / `date_from` / `date_to` filters as `Africa/Nairobi` business-date
     * windows over the `starts_at` timestamptz.
     *
     * @param  Builder<Appointment>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        $tz = (string) config('servana.scheduling.business_timezone', 'Africa/Nairobi');

        if (isset($filters['date'])) {
            $start = CarbonImmutable::parse((string) $filters['date'], $tz)->startOfDay();
            $query->where('starts_at', '>=', $start)->where('starts_at', '<', $start->addDay());

            return;
        }

        if (isset($filters['date_from'])) {
            $query->where('starts_at', '>=', CarbonImmutable::parse((string) $filters['date_from'], $tz)->startOfDay());
        }
        if (isset($filters['date_to'])) {
            $query->where('starts_at', '<', CarbonImmutable::parse((string) $filters['date_to'], $tz)->startOfDay()->addDay());
        }
    }
}
