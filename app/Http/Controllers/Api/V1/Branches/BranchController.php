<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Branches;

use App\Domain\Branches\Actions\ArchiveBranch;
use App\Domain\Branches\Actions\CreateBranch;
use App\Domain\Branches\Actions\UpdateBranch;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\BranchIndexRequest;
use App\Http\Requests\Branches\CreateBranchRequest;
use App\Http\Requests\Branches\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Branch CRUD (Scope §3.3, Plan §10.2/§10.3).
 *
 * Authority is the permission registry, not raw roles: mutating routes carry
 * EnsurePermission (`branches.create` for create/archive, `branch.profile.manage`
 * for update) and per-branch routes carry EnsureBranchScope (foreign branch ULID
 * → 404). Listing/viewing is branch-scoped. The Phase 7 coarse `assertAdmin`
 * check is removed — the backend boundary is the middleware/policy.
 */
final class BranchController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(BranchIndexRequest $request): AnonymousResourceCollection
    {
        $merchantId = $this->context->merchantId();
        $filters = $request->validated();

        $query = MerchantBranch::query()->where('merchant_id', $merchantId);

        // Branch-scoped users see only their assigned branches; admin sees all.
        if ($this->context->isBranchScoped()) {
            $query->whereIn('id', $this->context->branchIds());
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'name');

        return BranchResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function store(CreateBranchRequest $request, CreateBranch $action): JsonResponse
    {
        $merchant = $this->context->merchant();
        abort_if($merchant === null, 403);

        /** @var User $actor */
        $actor = $request->user();

        $branch = $action->handle($merchant, $actor, $request->validated());

        return BranchResource::make($branch)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(MerchantBranch $branch): BranchResource
    {
        return BranchResource::make($branch);
    }

    public function update(UpdateBranchRequest $request, MerchantBranch $branch, UpdateBranch $action): BranchResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return BranchResource::make($action->handle($branch, $actor, $request->validated()));
    }

    public function archive(Request $request, MerchantBranch $branch, ArchiveBranch $action): BranchResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $reason = $request->input('reason');

        return BranchResource::make($action->handle($branch, $actor, is_string($reason) ? $reason : null));
    }
}
