<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Domain\Catalogue\Actions\CreateServiceCategory;
use App\Domain\Catalogue\Actions\UpdateServiceCategory;
use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Concerns\ResolvesWriteBranch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\StoreServiceCategoryRequest;
use App\Http\Requests\Catalogue\UpdateServiceCategoryRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Service categories (Plan §39). Branch Manager-owned (`service.*`); branch-scoped
 * reads; route-bound ULIDs resolve inside tenant scope.
 */
final class ServiceCategoryController extends Controller
{
    use ResolvesWriteBranch;

    public function __construct(private readonly TenantContext $context) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ServiceCategory::class);

        $query = ServiceCategory::query()->withCount('services');
        ApiPagination::applySort($query, 'sort_order', 'sort_order', 'id');

        return ServiceCategoryResource::collection($query->paginate(ApiPagination::MAX_PER_PAGE));
    }

    public function store(StoreServiceCategoryRequest $request, CreateServiceCategory $action): JsonResponse
    {
        $this->authorize('create', ServiceCategory::class);

        $data = $request->validated();
        $branch = $this->resolveWriteBranch($this->context, $data['branch_id'] ?? null);

        /** @var User $actor */
        $actor = $request->user();
        $category = $action->handle($branch, $actor, $data);

        return ServiceCategoryResource::make($category)
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateServiceCategoryRequest $request, ServiceCategory $serviceCategory, UpdateServiceCategory $action): ServiceCategoryResource
    {
        $this->authorize('update', $serviceCategory);

        /** @var User $actor */
        $actor = $request->user();

        return ServiceCategoryResource::make($action->handle($serviceCategory, $actor, $request->validated()));
    }
}
