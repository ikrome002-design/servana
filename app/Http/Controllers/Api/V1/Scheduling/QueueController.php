<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scheduling;

use App\Domain\Catalogue\Enums\ServiceStatus;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Actions\AssignQueueEntry;
use App\Domain\Scheduling\Actions\CallQueueEntry;
use App\Domain\Scheduling\Actions\CancelQueueEntry;
use App\Domain\Scheduling\Actions\CompleteQueueEntry;
use App\Domain\Scheduling\Actions\ConvertAppointmentToQueue;
use App\Domain\Scheduling\Actions\CreateWalkInAndQueueEntry;
use App\Domain\Scheduling\Actions\MarkQueueEntryNoShow;
use App\Domain\Scheduling\Actions\ReorderQueueEntries;
use App\Domain\Scheduling\Actions\StartQueueEntry;
use App\Domain\Scheduling\Actions\TransferQueueEntry;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\Appointment;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Concerns\ResolvesWriteBranch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\AssignQueueEntryRequest;
use App\Http\Requests\Scheduling\CancelQueueEntryRequest;
use App\Http\Requests\Scheduling\ConvertAppointmentToQueueRequest;
use App\Http\Requests\Scheduling\QueueIndexRequest;
use App\Http\Requests\Scheduling\ReorderQueueEntriesRequest;
use App\Http\Requests\Scheduling\StoreWalkInRequest;
use App\Http\Requests\Scheduling\TransferQueueEntryRequest;
use App\Http\Resources\QueueEntryResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Front Office queue operations (Plan §37; Phase 16B). Front Office owns all
 * operational queue work (`queue.view/create/assign/transfer/reorder`); the
 * call/start/complete/cancel/no-show lifecycle is authorised through `queue.assign`.
 * Branch Manager has read-only visibility (QueueEntryPolicy via
 * `branch.dashboard.view`) and never operates entries. Reads are branch-scoped and
 * client contact is ALWAYS masked. Every mutation delegates to a transactional
 * domain action that re-authorizes, locks, validates state + the shared gates, and
 * writes audit events; merchant/branch/status/position/estimate/actor are derived
 * server-side.
 */
final class QueueController extends Controller
{
    use ResolvesWriteBranch;

    private const RELATIONS = ['service', 'client', 'assignedPersonnel', 'preferredPersonnel', 'walkIn', 'appointment'];

    /** Start/complete additionally surface the coupled Phase 16C service session. */
    private const RELATIONS_WITH_SESSION = [
        'service', 'client', 'assignedPersonnel', 'preferredPersonnel', 'walkIn', 'appointment',
        'serviceSession.service', 'serviceSession.client', 'serviceSession.personnel', 'serviceSession.queueEntry',
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function index(QueueIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QueueEntry::class);

        $filters = $request->validated();
        $query = QueueEntry::query()->with(self::RELATIONS);

        if (($filters['active'] ?? false)) {
            $query->whereIn('status', QueueEntry::statusValues(QueueEntryStatus::activeStatuses()));
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['assignment_mode'])) {
            $query->where('assignment_mode', $filters['assignment_mode']);
        }
        if (isset($filters['position'])) {
            $query->where('position', (int) $filters['position']);
        }
        if (isset($filters['service'])) {
            $query->where('service_id', $this->scopedId(Service::query(), (string) $filters['service']));
        }
        if (isset($filters['assigned_personnel'])) {
            $query->where('staff_profile_id', $this->scopedId(StaffProfile::query(), (string) $filters['assigned_personnel']));
        }
        $this->applyQueuedFilters($query, $filters);

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'position');

        return QueueEntryResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function show(QueueEntry $queueEntry): QueueEntryResource
    {
        $this->authorize('view', $queueEntry);

        return QueueEntryResource::make($queueEntry->load(self::RELATIONS));
    }

    public function storeWalkIn(StoreWalkInRequest $request, CreateWalkInAndQueueEntry $action): JsonResponse
    {
        $this->authorize('create', QueueEntry::class);

        $data = $request->validated();
        $mode = QueueAssignmentMode::from((string) $data['assignment_mode']);
        if ($mode === QueueAssignmentMode::PreferredPersonnel) {
            $this->authorize('selectPreferred', QueueEntry::class);
        }

        $branch = $this->resolveWriteBranch($this->context, $data['branch_id'] ?? null);
        $service = $this->branchModel(
            Service::query()->where('status', ServiceStatus::Active->value),
            $branch->id,
            (string) $data['service'],
        );

        $existingClient = isset($data['client'])
            ? $this->branchModel(Client::query(), $branch->id, (string) $data['client'])
            : null;
        $newClientData = $existingClient === null ? ($data['new_client'] ?? null) : null;

        $target = isset($data['personnel'])
            ? $this->branchModel(StaffProfile::query(), $branch->id, (string) $data['personnel'], 'primary_branch_id')
            : null;
        $preferred = isset($data['preferred_personnel'])
            ? $this->branchModel(StaffProfile::query(), $branch->id, (string) $data['preferred_personnel'], 'primary_branch_id')
            : null;

        /** @var User $actor */
        $actor = $request->user();

        $entry = $action->handle(
            $branch,
            $actor,
            $existingClient,
            is_array($newClientData) ? $newClientData : null,
            $service,
            $mode,
            $target,
            $preferred,
            isset($data['estimated_wait_override_minutes']) ? (int) $data['estimated_wait_override_minutes'] : null,
            $data['estimated_wait_override_reason'] ?? null,
        );

        return QueueEntryResource::make($entry->load(self::RELATIONS))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function convertAppointment(ConvertAppointmentToQueueRequest $request, Appointment $appointment, ConvertAppointmentToQueue $action): JsonResponse
    {
        $this->authorize('create', QueueEntry::class);

        $data = $request->validated();
        $mode = QueueAssignmentMode::from((string) ($data['assignment_mode'] ?? 'next_available'));
        if ($mode === QueueAssignmentMode::PreferredPersonnel) {
            $this->authorize('selectPreferred', QueueEntry::class);
        }

        $target = isset($data['personnel'])
            ? $this->branchModel(StaffProfile::query(), $appointment->branch_id, (string) $data['personnel'], 'primary_branch_id')
            : null;
        $preferred = isset($data['preferred_personnel'])
            ? $this->branchModel(StaffProfile::query(), $appointment->branch_id, (string) $data['preferred_personnel'], 'primary_branch_id')
            : null;

        /** @var User $actor */
        $actor = $request->user();

        $entry = $action->handle($appointment, $actor, $mode, $target, $preferred);

        return QueueEntryResource::make($entry->load(self::RELATIONS))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function assign(AssignQueueEntryRequest $request, QueueEntry $queueEntry, AssignQueueEntry $action): QueueEntryResource
    {
        $this->authorize('operate', $queueEntry);

        $data = $request->validated();
        $mode = QueueAssignmentMode::from((string) $data['assignment_mode']);
        if ($mode === QueueAssignmentMode::PreferredPersonnel) {
            $this->authorize('selectPreferred', $queueEntry);
        }

        $target = isset($data['personnel'])
            ? $this->branchModel(StaffProfile::query(), $queueEntry->branch_id, (string) $data['personnel'], 'primary_branch_id')
            : null;
        $preferred = isset($data['preferred_personnel'])
            ? $this->branchModel(StaffProfile::query(), $queueEntry->branch_id, (string) $data['preferred_personnel'], 'primary_branch_id')
            : null;

        /** @var User $actor */
        $actor = $request->user();

        return QueueEntryResource::make(
            $action->handle($queueEntry, $actor, $mode, $target, $preferred, $data['reason'] ?? null)->load(self::RELATIONS),
        );
    }

    public function call(QueueEntry $queueEntry, CallQueueEntry $action): QueueEntryResource
    {
        $this->authorize('operate', $queueEntry);

        return QueueEntryResource::make($action->handle($queueEntry, $this->actor())->load(self::RELATIONS));
    }

    public function start(QueueEntry $queueEntry, StartQueueEntry $action): QueueEntryResource
    {
        $this->authorize('operate', $queueEntry);

        return QueueEntryResource::make($action->handle($queueEntry, $this->actor())->load(self::RELATIONS_WITH_SESSION));
    }

    public function complete(QueueEntry $queueEntry, CompleteQueueEntry $action): QueueEntryResource
    {
        $this->authorize('operate', $queueEntry);

        $result = $action->handle($queueEntry, $this->actor());

        return QueueEntryResource::make($result['entry']->load(self::RELATIONS_WITH_SESSION));
    }

    public function transfer(TransferQueueEntryRequest $request, QueueEntry $queueEntry, TransferQueueEntry $action): QueueEntryResource
    {
        $this->authorize('transfer', $queueEntry);

        $data = $request->validated();
        $target = isset($data['personnel'])
            ? $this->branchModel(StaffProfile::query(), $queueEntry->branch_id, (string) $data['personnel'], 'primary_branch_id')
            : null;

        /** @var User $actor */
        $actor = $request->user();

        return QueueEntryResource::make(
            $action->handle($queueEntry, $actor, $target, (string) $data['reason'])->load(self::RELATIONS),
        );
    }

    public function cancel(CancelQueueEntryRequest $request, QueueEntry $queueEntry, CancelQueueEntry $action): QueueEntryResource
    {
        $this->authorize('operate', $queueEntry);

        /** @var User $actor */
        $actor = $request->user();

        return QueueEntryResource::make(
            $action->handle($queueEntry, $actor, (string) $request->validated('reason'))->load(self::RELATIONS),
        );
    }

    public function noShow(QueueEntry $queueEntry, MarkQueueEntryNoShow $action): QueueEntryResource
    {
        $this->authorize('operate', $queueEntry);

        return QueueEntryResource::make($action->handle($queueEntry, $this->actor())->load(self::RELATIONS));
    }

    public function reorder(ReorderQueueEntriesRequest $request, ReorderQueueEntries $action): AnonymousResourceCollection
    {
        $this->authorize('reorder', QueueEntry::class);

        $data = $request->validated();
        $branch = $this->resolveWriteBranch($this->context, $data['branch_id'] ?? null);

        /** @var User $actor */
        $actor = $request->user();

        /** @var list<string> $order */
        $order = array_values($data['order']);

        return QueueEntryResource::collection($action->handle($branch, $actor, $order));
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
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
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function scopedId(Builder $query, string $ulid): int
    {
        return (int) ($query->where('ulid', $ulid)->value('id') ?? 0);
    }

    /**
     * @param  Builder<QueueEntry>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyQueuedFilters(Builder $query, array $filters): void
    {
        $tz = (string) config('servana.scheduling.business_timezone', 'Africa/Nairobi');

        if (isset($filters['queued_from'])) {
            $query->where('queued_at', '>=', CarbonImmutable::parse((string) $filters['queued_from'], $tz)->startOfDay());
        }
        if (isset($filters['queued_to'])) {
            $query->where('queued_at', '<', CarbonImmutable::parse((string) $filters['queued_to'], $tz)->startOfDay()->addDay());
        }
    }
}
