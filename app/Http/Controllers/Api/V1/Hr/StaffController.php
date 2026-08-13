<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Hr;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Hr\Services\StaffLifecycleService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StaffIndexRequest;
use App\Http\Requests\Hr\StaffLifecycleRequest;
use App\Http\Resources\StaffProfileResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff roster + lifecycle (Scope §3.4, Plan §10.2/§10.3).
 *
 * Authority is StaffProfilePolicy (the §10.3 permission registry), and READ is a
 * distinct authority from MANAGE (Phase 23 security remediation):
 *   - READ  (`index`/`show`) → `staff.view`, HR-only, branch-scoped.
 *   - MANAGE (`suspend`/`activate`/`deactivate`) → unchanged `staff.suspend` (HR,
 *     own branch) or `branches.manage_users_lifecycle` (Merchant Admin, merchant-wide).
 *
 * `index` previously authorized NOTHING — no permission middleware, no policy call —
 * so any authenticated merchant member could enumerate the branch roster including
 * personnel phone numbers (Plan §9.1 personnel-contact extraction; RK-05). The
 * Branch Manager's read-only schedule picker is served by the narrow
 * `branch.personnel-options.index` endpoint, never by widening `staff.view`.
 *
 * Cross-merchant staff is 404'd (no existence leak) before authorization.
 */
final class StaffController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(StaffIndexRequest $request): AnonymousResourceCollection
    {
        // The collection READ boundary. Without this the route has none: GET carries no
        // RouteClass and no EnsurePermission, so the policy IS the server-side authority.
        $this->authorize('viewAny', StaffProfile::class);

        $filters = $request->validated();

        $query = StaffProfile::query()
            ->where('merchant_id', $this->context->merchantId())
            ->with(['merchantUser', 'primaryBranch']);

        if ($this->context->isBranchScoped()) {
            $query->whereIn('primary_branch_id', $this->context->branchIds());
        }

        if (isset($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(static function (Builder $search) use ($term): void {
                $search
                    ->where('display_name', 'ilike', $term)
                    ->orWhere('first_name', 'ilike', $term)
                    ->orWhere('last_name', 'ilike', $term)
                    ->orWhere('role_title', 'ilike', $term)
                    ->orWhere('phone', 'ilike', $term);
            });
        }

        if (isset($filters['role'])) {
            $query->whereHas('merchantUser', static fn (Builder $membership): Builder => $membership->where('role', $filters['role']));
        }

        if (isset($filters['status'])) {
            $query->whereHas('merchantUser', static fn (Builder $membership): Builder => $membership->where('status', $filters['status']));
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
        // READ authority (`staff.view`) — deliberately NOT the mutation authority.
        $this->authorizeScoped('view', $staff);

        return StaffProfileResource::make($staff->load(['merchantUser', 'primaryBranch']));
    }

    public function suspend(StaffLifecycleRequest $request, StaffProfile $staff, StaffLifecycleService $service): StaffProfileResource
    {
        $this->authorizeScoped('manage', $staff);
        $membership = $staff->merchantUser;
        abort_if($membership === null, 404);

        $reason = $request->validated('reason');
        $service->suspend($membership, $request->user(), is_string($reason) ? $reason : null);

        return StaffProfileResource::make($staff->fresh(['merchantUser', 'primaryBranch']));
    }

    public function activate(StaffLifecycleRequest $request, StaffProfile $staff, StaffLifecycleService $service): StaffProfileResource
    {
        $this->authorizeScoped('manage', $staff);
        $membership = $staff->merchantUser;
        abort_if($membership === null, 404);

        $service->activate($membership, $request->user());

        return StaffProfileResource::make($staff->fresh(['merchantUser', 'primaryBranch']));
    }

    public function deactivate(StaffLifecycleRequest $request, StaffProfile $staff, StaffLifecycleService $service): StaffProfileResource
    {
        $this->authorizeScoped('manage', $staff);
        $membership = $staff->merchantUser;
        abort_if($membership === null, 404);

        $reason = $request->validated('reason');
        $service->deactivate($membership, $request->user(), is_string($reason) ? $reason : null);

        return StaffProfileResource::make($staff->fresh(['merchantUser', 'primaryBranch']));
    }

    /**
     * 404 a foreign-merchant staff profile (no existence leak), then authorize
     * the given ability via StaffProfilePolicy (the §10.3 permission registry).
     * Used for BOTH the record read (`view` → `staff.view`) and the lifecycle
     * mutations (`manage`) — the policy keeps the two authorities separate.
     */
    private function authorizeScoped(string $ability, StaffProfile $staff): void
    {
        abort_if($staff->merchant_id !== $this->context->merchantId(), 404);

        $this->authorize($ability, $staff);
    }
}
