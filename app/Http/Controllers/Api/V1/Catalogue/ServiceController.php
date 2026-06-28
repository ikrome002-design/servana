<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Domain\Catalogue\Actions\ArchiveService;
use App\Domain\Catalogue\Actions\CreateService;
use App\Domain\Catalogue\Actions\UpdateService;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Concerns\ResolvesWriteBranch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\ServiceIndexRequest;
use App\Http\Requests\Catalogue\StoreServiceRequest;
use App\Http\Requests\Catalogue\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Service catalogue (Plan §39). Branch Manager owns it (`service.*`); authority is
 * ServicePolicy + the EnsurePermission route middleware. Reads are branch-scoped
 * (BranchScope); route-bound ULIDs resolve inside tenant scope so a foreign/other-
 * branch service 404s. The legacy preferred-personnel fee is never read/written.
 */
final class ServiceController extends Controller
{
    use ResolvesWriteBranch;

    public function __construct(private readonly TenantContext $context) {}

    public function index(ServiceIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Service::class);

        $filters = $request->validated();

        $query = Service::query()->with(['category', 'branch']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['category_id'])) {
            $categoryId = ServiceCategory::query()->where('ulid', $filters['category_id'])->value('id');
            $query->where('category_id', $categoryId ?? 0);
        }

        if (isset($filters['q']) && $filters['q'] !== '') {
            $query->where('name', 'ilike', '%'.$filters['q'].'%');
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'name');

        return ServiceResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function show(Service $service): ServiceResource
    {
        $this->authorize('view', $service);

        return ServiceResource::make($service->load(['category', 'branch']));
    }

    public function store(StoreServiceRequest $request, CreateService $action): JsonResponse
    {
        $this->authorize('create', Service::class);

        $data = $request->validated();
        $branch = $this->resolveWriteBranch($this->context, $data['branch_id'] ?? null);

        /** @var ServiceCategory $category */
        $category = ServiceCategory::query()
            ->where('branch_id', $branch->id)
            ->where('ulid', (string) $data['category_id'])
            ->firstOr(fn () => abort(422, 'The selected category is invalid for this branch.'));

        /** @var User $actor */
        $actor = $request->user();
        $service = $action->handle($branch, $category, $actor, $data);

        return ServiceResource::make($service->load(['category', 'branch']))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateServiceRequest $request, Service $service, UpdateService $action): ServiceResource
    {
        $this->authorize('update', $service);

        $data = $request->validated();
        $category = null;

        if (isset($data['category_id'])) {
            /** @var ServiceCategory $category */
            $category = ServiceCategory::query()
                ->where('branch_id', $service->branch_id)
                ->where('ulid', (string) $data['category_id'])
                ->firstOr(fn () => abort(422, 'The selected category is invalid for this branch.'));
        }

        /** @var User $actor */
        $actor = $request->user();

        return ServiceResource::make($action->handle($service, $actor, $data, $category)->load(['category', 'branch']));
    }

    public function archive(Service $service, ArchiveService $action): ServiceResource
    {
        $this->authorize('archive', $service);

        /** @var User $actor */
        $actor = request()->user();

        return ServiceResource::make($action->handle($service, $actor)->load(['category', 'branch']));
    }
}
