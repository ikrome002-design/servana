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
 * Staff invitations (Scope §3.2/§3.4, Plan §10.2). Authority (coarse until the
 * Phase 8 registry):
 *   - Merchant Admin may invite ONLY branch_manager + hr (Scope §3.2).
 *   - HR may invite personnel/front_office/finance/audit within its own branch
 *     scope (Scope §3.4 same-branch).
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
        $this->assertManages($invitation);

        return StaffInvitationResource::make($action->handle($invitation)->load('branch'));
    }

    public function revoke(StaffInvitation $invitation, RevokeStaffInvitation $action): StaffInvitationResource
    {
        $this->assertManages($invitation);

        return StaffInvitationResource::make($action->handle($invitation)->load('branch'));
    }

    /** Enforce the §3.2/§3.4 authority boundary for who may invite which role/branch. */
    private function assertCanInvite(MerchantUserRole $role, MerchantBranch $branch): void
    {
        $actorRole = $this->context->role();

        if ($actorRole === MerchantUserRole::MerchantAdmin) {
            // Admin adds ONLY branch_manager + hr.
            abort_unless(
                in_array($role, [MerchantUserRole::BranchManager, MerchantUserRole::Hr], true),
                403,
            );

            return;
        }

        if ($actorRole === MerchantUserRole::Hr) {
            // HR adds non-admin operational roles, within its own branch scope.
            abort_if(in_array($role, [MerchantUserRole::MerchantAdmin, MerchantUserRole::BranchManager, MerchantUserRole::Hr], true), 403);
            abort_unless($this->context->canAccessBranch($branch->id), 403);

            return;
        }

        abort(403);
    }

    private function assertManages(StaffInvitation $invitation): void
    {
        // Never leak existence of another merchant's invitation.
        abort_if($invitation->merchant_id !== $this->context->merchantId(), 404);

        $actorRole = $this->context->role();
        abort_unless(in_array($actorRole, [MerchantUserRole::MerchantAdmin, MerchantUserRole::Hr], true), 403);

        if ($actorRole === MerchantUserRole::Hr) {
            abort_unless($this->context->canAccessBranch($invitation->branch_id), 403);
        }
    }
}
