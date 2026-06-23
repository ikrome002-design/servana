<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Hr;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Hr\Services\StaffLifecycleService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StaffIndexRequest;
use App\Http\Resources\StaffProfileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff roster + lifecycle (Scope §3.4, Plan §10.2/§10.3).
 *
 * Authority is StaffProfilePolicy (the §10.3 permission registry): HR manages
 * operational staff in its own branch scope (`staff.suspend`); Merchant Admin
 * manages branch-user lifecycle merchant-wide (`branches.manage_users_lifecycle`).
 * Cross-merchant staff is 404'd (no existence leak) before authorization. The
 * Phase 7 coarse `assertManages` role check is replaced by the policy.
 */
final class StaffController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(StaffIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $query = StaffProfile::query()
            ->where('merchant_id', $this->context->merchantId())
            ->with(['merchantUser', 'primaryBranch']);

        if ($this->context->isBranchScoped()) {
            $query->whereIn('primary_branch_id', $this->context->branchIds());
        }

        if (isset($filters['employment_status'])) {
            $query->where('employment_status', $filters['employment_status']);
        }

        if (isset($filters['employment_type'])) {
            $query->where('employment_type', $filters['employment_type']);
        }

        ApiPagination::applySort($query, $filters['sort'] ?? null, 'display_name');

        return StaffProfileResource::collection(
            $query->paginate(ApiPagination::perPage($filters))->withQueryString(),
        );
    }

    public function show(StaffProfile $staff): StaffProfileResource
    {
        $this->authorizeManages('view', $staff);

        return StaffProfileResource::make($staff->load(['merchantUser', 'primaryBranch']));
    }

    public function suspend(Request $request, StaffProfile $staff, StaffLifecycleService $service): StaffProfileResource
    {
        $this->authorizeManages('manage', $staff);
        $membership = $staff->merchantUser;
        abort_if($membership === null, 404);

        $reason = $request->input('reason');
        $service->suspend($membership, $request->user(), is_string($reason) ? $reason : null);

        return StaffProfileResource::make($staff->fresh(['merchantUser', 'primaryBranch']));
    }

    public function activate(Request $request, StaffProfile $staff, StaffLifecycleService $service): StaffProfileResource
    {
        $this->authorizeManages('manage', $staff);
        $membership = $staff->merchantUser;
        abort_if($membership === null, 404);

        $service->activate($membership, $request->user());

        return StaffProfileResource::make($staff->fresh(['merchantUser', 'primaryBranch']));
    }

    public function deactivate(Request $request, StaffProfile $staff, StaffLifecycleService $service): StaffProfileResource
    {
        $this->authorizeManages('manage', $staff);
        $membership = $staff->merchantUser;
        abort_if($membership === null, 404);

        $reason = $request->input('reason');
        $service->deactivate($membership, $request->user(), is_string($reason) ? $reason : null);

        return StaffProfileResource::make($staff->fresh(['merchantUser', 'primaryBranch']));
    }

    /**
     * 404 a foreign-merchant staff profile (no existence leak), then authorize
     * the given ability via StaffProfilePolicy (the §10.3 permission registry).
     */
    private function authorizeManages(string $ability, StaffProfile $staff): void
    {
        abort_if($staff->merchant_id !== $this->context->merchantId(), 404);

        $this->authorize($ability, $staff);
    }
}
