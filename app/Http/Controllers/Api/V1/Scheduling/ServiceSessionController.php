<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Scheduling;

use App\Domain\Scheduling\Actions\CancelServiceSession;
use App\Domain\Scheduling\Actions\UpdateServiceNotes;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\CancelServiceSessionRequest;
use App\Http\Requests\Scheduling\ServiceSessionIndexRequest;
use App\Http\Requests\Scheduling\UpdateServiceNotesRequest;
use App\Http\Resources\ServiceSessionResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Front Office service-session operations (Plan §25.2; Phase 16C). Front Office owns
 * the operational session lifecycle (`service_session.view/start/complete/cancel`);
 * start + complete are driven by the queue orchestration routes (queue start/complete)
 * — this controller owns list/detail, cancellation, and service-notes editing. Reads
 * are branch-scoped (the model's BelongsToBranch global scope) and client contact is
 * ALWAYS masked. Branch Manager has NO session authority. Every mutation delegates to
 * a transactional domain action that re-authorizes, locks, validates state, and writes
 * audit events; merchant/branch/status/timestamps/actor are derived server-side.
 */
final class ServiceSessionController extends Controller
{
    private const RELATIONS = ['service', 'client', 'personnel', 'queueEntry'];

    public function index(ServiceSessionIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ServiceSession::class);

        $filters = $request->validated();
        $query = ServiceSession::query()->with(self::RELATIONS);

        if (($filters['active'] ?? false)) {
            $query->whereIn('status', ServiceSessionStatus::values(ServiceSessionStatus::activeStatuses()));
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'created_at');

        return ServiceSessionResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function show(ServiceSession $serviceSession): ServiceSessionResource
    {
        $this->authorize('view', $serviceSession);

        return ServiceSessionResource::make($serviceSession->load(self::RELATIONS));
    }

    public function cancel(CancelServiceSessionRequest $request, ServiceSession $serviceSession, CancelServiceSession $action): ServiceSessionResource
    {
        $this->authorize('cancel', $serviceSession);

        /** @var User $actor */
        $actor = $request->user();

        return ServiceSessionResource::make(
            $action->handle($serviceSession, $actor, (string) $request->validated('reason'))->load(self::RELATIONS),
        );
    }

    public function updateNotes(UpdateServiceNotesRequest $request, ServiceSession $serviceSession, UpdateServiceNotes $action): ServiceSessionResource
    {
        $this->authorize('update', $serviceSession);

        return ServiceSessionResource::make(
            $action->handle($serviceSession, (string) ($request->validated('notes') ?? ''))->load(self::RELATIONS),
        );
    }
}
