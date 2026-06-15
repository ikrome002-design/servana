<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Hr;

use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Hr\Services\StaffLifecycleService;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffProfileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff roster + lifecycle (Scope §3.4, Plan §10.2). Suspend/activate/deactivate
 * are Merchant Admin or HR authority (HR within its own branch scope). Coarse
 * role checks here are replaced by the permission registry in Phase 8.
 */
final class StaffController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): AnonymousResourceCollection
    {
        $query = StaffProfile::query()
            ->where('merchant_id', $this->context->merchantId())
            ->with(['merchantUser', 'primaryBranch']);

        if ($this->context->isBranchScoped()) {
            $query->whereIn('primary_branch_id', $this->context->branchIds());
        }

        return StaffProfileResource::collection($query->orderBy('display_name')->get());
    }

    public function show(StaffProfile $staff): StaffProfileResource
    {
        $this->assertManages($staff);

        return StaffProfileResource::make($staff->load(['merchantUser', 'primaryBranch']));
    }

    public function suspend(Request $request, StaffProfile $staff, StaffLifecycleService $service): StaffProfileResource
    {
        $this->assertManages($staff);
        $membership = $staff->merchantUser;
        abort_if($membership === null, 404);

        $reason = $request->input('reason');
        $service->suspend($membership, $request->user(), is_string($reason) ? $reason : null);

        return StaffProfileResource::make($staff->fresh(['merchantUser', 'primaryBranch']));
    }

    public function activate(Request $request, StaffProfile $staff, StaffLifecycleService $service): StaffProfileResource
    {
        $this->assertManages($staff);
        $membership = $staff->merchantUser;
        abort_if($membership === null, 404);

        $service->activate($membership, $request->user());

        return StaffProfileResource::make($staff->fresh(['merchantUser', 'primaryBranch']));
    }

    public function deactivate(Request $request, StaffProfile $staff, StaffLifecycleService $service): StaffProfileResource
    {
        $this->assertManages($staff);
        $membership = $staff->merchantUser;
        abort_if($membership === null, 404);

        $reason = $request->input('reason');
        $service->deactivate($membership, $request->user(), is_string($reason) ? $reason : null);

        return StaffProfileResource::make($staff->fresh(['merchantUser', 'primaryBranch']));
    }

    private function assertManages(StaffProfile $staff): void
    {
        // Never leak another merchant's staff.
        abort_if($staff->merchant_id !== $this->context->merchantId(), 404);

        $actorRole = $this->context->role();
        abort_unless(in_array($actorRole, [MerchantUserRole::MerchantAdmin, MerchantUserRole::Hr], true), 403);

        if ($actorRole === MerchantUserRole::Hr) {
            abort_unless($this->context->canAccessBranch($staff->primary_branch_id), 403);
        }
    }
}
