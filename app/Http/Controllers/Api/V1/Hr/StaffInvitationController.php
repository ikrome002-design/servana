<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Hr;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Actions\CreateStaffInvitation;
use App\Domain\Hr\Actions\ResendStaffInvitation;
use App\Domain\Hr\Actions\RevokeStaffInvitation;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\CreateStaffInvitationRequest;
use App\Http\Resources\StaffInvitationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Staff invitations (Scope §3.2/§3.4, Plan §10.2/§10.3).
 *
 * Capability to invite at all is StaffInvitationPolicy (`staff.invite` /
 * `branches.manage_users_lifecycle`). WHICH target roles/branches each actor may
 * invite is the §3.2/§3.4 boundary, derived here from the resolved capabilities
 * (no longer raw roles):
 *   - `branches.manage_users_lifecycle` (Merchant Admin) → branch_manager + hr only.
 *   - `staff.invite` (HR) → operational roles within its own branch scope.
 */
final class StaffInvitationController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): AnonymousResourceCollection
    {
        $query = StaffInvitation::query()
            ->where('merchant_id', $this->context->merchantId())
            ->with('branch');

        if ($this->context->isBranchScoped()) {
            $query->whereIn('branch_id', $this->context->branchIds());
        }

        return StaffInvitationResource::collection($query->latest()->get());
    }

    public function store(CreateStaffInvitationRequest $request, CreateStaffInvitation $action): JsonResponse
    {
        $this->authorize('create', StaffInvitation::class);

        $merchant = $this->context->merchant();
        abort_if($merchant === null, 403);

        $validated = $request->validated();
        $role = MerchantUserRole::from((string) $validated['role']);

        /** @var MerchantBranch $branch */
        $branch = MerchantBranch::query()
            ->where('merchant_id', $merchant->id)
            ->where('ulid', (string) $validated['branch_id'])
            ->firstOrFail();

        $this->assertCanInvite($role, $branch);

        /** @var User $actor */
        $actor = $request->user();

        $invitation = $action->handle($merchant, $branch, $actor, (string) $validated['email'], [
            'role' => $role,
            'role_title' => $validated['role_title'] ?? null,
            'service_eligibility_ids' => $validated['service_eligibility_ids'] ?? null,
        ]);

        return StaffInvitationResource::make($invitation->load('branch'))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function resend(StaffInvitation $invitation, ResendStaffInvitation $action): StaffInvitationResource
    {
        $this->authorizeManages($invitation);

        return StaffInvitationResource::make($action->handle($invitation)->load('branch'));
    }

    public function revoke(StaffInvitation $invitation, RevokeStaffInvitation $action): StaffInvitationResource
    {
        $this->authorizeManages($invitation);

        return StaffInvitationResource::make($action->handle($invitation)->load('branch'));
    }

    /**
     * Enforce the §3.2/§3.4 authority boundary for who may invite which
     * role/branch, derived from resolved capabilities (Plan §10.3).
     */
    private function assertCanInvite(MerchantUserRole $role, MerchantBranch $branch): void
    {
        // Merchant Admin (branch-user lifecycle) adds ONLY branch_manager + hr.
        if ($this->context->can('branches.manage_users_lifecycle')) {
            abort_unless(
                in_array($role, [MerchantUserRole::BranchManager, MerchantUserRole::Hr], true),
                403,
            );

            return;
        }

        // HR (staff.invite) adds non-admin operational roles, within its branch scope.
        if ($this->context->can('staff.invite')) {
            abort_if(in_array($role, [MerchantUserRole::MerchantAdmin, MerchantUserRole::BranchManager, MerchantUserRole::Hr], true), 403);
            abort_unless($this->context->canAccessBranch($branch->id), 403);

            return;
        }

        abort(403);
    }

    /**
     * 404 a foreign-merchant invitation (no existence leak), then authorize via
     * StaffInvitationPolicy.
     */
    private function authorizeManages(StaffInvitation $invitation): void
    {
        abort_if($invitation->merchant_id !== $this->context->merchantId(), 404);

        $this->authorize('manage', $invitation);
    }
}
