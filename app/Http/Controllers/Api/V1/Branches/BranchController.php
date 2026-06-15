<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Branches;

use App\Domain\Branches\Actions\ArchiveBranch;
use App\Domain\Branches\Actions\CreateBranch;
use App\Domain\Branches\Actions\UpdateBranch;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\CreateBranchRequest;
use App\Http\Requests\Branches\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Branch CRUD (Scope §3.3, Plan §10.2). Create/update/archive are Merchant
 * Administrator authority; listing/viewing is branch-scoped. Coarse role checks
 * here are replaced by the permission registry (EnsurePermission) in Phase 8.
 */
final class BranchController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): AnonymousResourceCollection
    {
        $merchantId = $this->context->merchantId();

        $query = MerchantBranch::query()->where('merchant_id', $merchantId);

        // Branch-scoped users see only their assigned branches; admin sees all.
        if ($this->context->isBranchScoped()) {
            $query->whereIn('id', $this->context->branchIds());
        }

        return BranchResource::collection($query->orderBy('name')->get());
    }

    public function store(CreateBranchRequest $request, CreateBranch $action): JsonResponse
    {
        $this->assertAdmin();

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
        $this->assertAdmin();

        /** @var User $actor */
        $actor = $request->user();

        return BranchResource::make($action->handle($branch, $actor, $request->validated()));
    }

    public function archive(Request $request, MerchantBranch $branch, ArchiveBranch $action): BranchResource
    {
        $this->assertAdmin();

        /** @var User $actor */
        $actor = $request->user();
        $reason = $request->input('reason');

        return BranchResource::make($action->handle($branch, $actor, is_string($reason) ? $reason : null));
    }

    /** Coarse authority gate until the Phase 8 permission registry. */
    private function assertAdmin(): void
    {
        abort_unless($this->context->role() === MerchantUserRole::MerchantAdmin, 403);
    }
}
