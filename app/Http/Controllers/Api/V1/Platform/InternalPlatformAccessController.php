<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Auth\Mfa\MfaManager;
use App\Domain\PlatformAccess\Actions\ChangePlatformAccessStatus;
use App\Domain\PlatformAccess\Actions\InvitePlatformAdministrator;
use App\Domain\PlatformAccess\Actions\ResendPlatformAccessInvitation;
use App\Domain\PlatformAccess\Actions\RevokePlatformAccessInvitation;
use App\Domain\PlatformAccess\Actions\RevokePlatformAdministratorSessions;
use App\Domain\PlatformAccess\Actions\UpdatePlatformAccessPermissions;
use App\Domain\PlatformAccess\Enums\PlatformAccessStatus;
use App\Domain\PlatformAccess\Models\PlatformAccessInvitation;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Domain\Sessions\Models\SessionFamily;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\InvitePlatformAdministratorRequest;
use App\Http\Requests\Platform\PlatformAccessLifecycleReasonRequest;
use App\Http\Requests\Platform\UpdatePlatformAccessPermissionsRequest;
use App\Http\Resources\PlatformAccessInvitationResource;
use App\Http\Resources\PlatformAccessMembershipResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Internal platform access (COR-UI08-001 §11; navigation map §5.4.19, /platform-access).
 *
 * Reads require `platform.internal_access.view`; every mutation requires
 * `platform.internal_access.manage` plus MFA, a fresh `platform_access_administration` step-up, a
 * mandatory reason and idempotency — all enforced by the route middleware.
 *
 * The self-protection and lockout rules are NOT enforced here. They live in
 * `PlatformAdministratorQuorum`, inside the mutating transaction under a row lock, because a
 * controller check could be raced by a concurrent request and a disabled button is not enforcement
 * at all.
 *
 * NOTHING ON THIS SURFACE CAN TOUCH A MERCHANT STRUCTURE: there is no role field, no merchant field
 * and no branch field on any request, and the actions never write `merchant_users`,
 * `branch_user_assignments` or `staff_profiles`.
 */
final class InternalPlatformAccessController extends Controller
{
    public function __construct(private readonly MfaManager $mfa) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('view', PlatformAccessMembership::class);

        $memberships = PlatformAccessMembership::query()
            ->with(['user', 'permissionOverrides.permission'])
            ->orderByRaw("case status when 'active' then 0 when 'invited' then 1 when 'suspended' then 2 else 3 end")
            ->orderBy('id')
            ->paginate(25);

        return PlatformAccessMembershipResource::collection(
            $memberships->through(fn (PlatformAccessMembership $membership): PlatformAccessMembershipResource => new PlatformAccessMembershipResource(
                $membership,
                $this->activeSessionCount($membership),
                $this->isMfaEnrolled($membership),
            )),
        );
    }

    public function show(PlatformAccessMembership $platformAccessMembership): PlatformAccessMembershipResource
    {
        $this->authorize('view', PlatformAccessMembership::class);

        $platformAccessMembership->load(['user', 'permissionOverrides.permission']);

        return new PlatformAccessMembershipResource(
            $platformAccessMembership,
            $this->activeSessionCount($platformAccessMembership),
            $this->isMfaEnrolled($platformAccessMembership),
        );
    }

    public function invitations(): AnonymousResourceCollection
    {
        $this->authorize('view', PlatformAccessMembership::class);

        return PlatformAccessInvitationResource::collection(
            PlatformAccessInvitation::query()->with('invitedBy')->orderByDesc('id')->paginate(25),
        );
    }

    /**
     * Enumeration-safe: the response is the SAME shape and status whether the address was newly
     * invited, had an existing invitation rotated, or already holds active access. Nothing here
     * discloses whether a user exists.
     */
    public function invite(InvitePlatformAdministratorRequest $request, InvitePlatformAdministrator $action): JsonResponse
    {
        $this->authorize('manage', PlatformAccessMembership::class);

        /** @var User $actor */
        $actor = $request->user();

        $result = $action->handle(
            (string) $request->validated('email'),
            (string) $request->validated('reason'),
            $actor,
            (string) app()->environment(),
        );

        return response()->json([
            'data' => [
                'accepted' => true,
                'message' => 'If this address can be invited, an invitation has been sent.',
            ],
        ], 202);
    }

    public function resendInvitation(
        PlatformAccessLifecycleReasonRequest $request,
        PlatformAccessInvitation $platformAccessInvitation,
        ResendPlatformAccessInvitation $action,
    ): PlatformAccessInvitationResource {
        $this->authorize('manage', PlatformAccessMembership::class);

        /** @var User $actor */
        $actor = $request->user();

        // The rotated raw token goes to the delivery path, never into the response.
        $result = $action->handle($platformAccessInvitation, $actor);

        return PlatformAccessInvitationResource::make($result['invitation']);
    }

    public function revokeInvitation(
        PlatformAccessLifecycleReasonRequest $request,
        PlatformAccessInvitation $platformAccessInvitation,
        RevokePlatformAccessInvitation $action,
    ): PlatformAccessInvitationResource {
        $this->authorize('manage', PlatformAccessMembership::class);

        /** @var User $actor */
        $actor = $request->user();

        return PlatformAccessInvitationResource::make(
            $action->handle($platformAccessInvitation, (string) $request->validated('reason'), $actor),
        );
    }

    public function updatePermissions(
        UpdatePlatformAccessPermissionsRequest $request,
        PlatformAccessMembership $platformAccessMembership,
        UpdatePlatformAccessPermissions $action,
    ): PlatformAccessMembershipResource {
        $this->authorize('manage', PlatformAccessMembership::class);

        /** @var User $actor */
        $actor = $request->user();

        /** @var list<string> $denied */
        $denied = $request->validated('denied_permissions');

        $updated = $action->handle($platformAccessMembership, $denied, (string) $request->validated('reason'), $actor);
        $updated->load(['user', 'permissionOverrides.permission']);

        return new PlatformAccessMembershipResource(
            $updated,
            $this->activeSessionCount($updated),
            $this->isMfaEnrolled($updated),
        );
    }

    public function suspend(
        PlatformAccessLifecycleReasonRequest $request,
        PlatformAccessMembership $platformAccessMembership,
        ChangePlatformAccessStatus $action,
    ): PlatformAccessMembershipResource {
        return $this->transition($request, $platformAccessMembership, $action, PlatformAccessStatus::Suspended);
    }

    public function reactivate(
        PlatformAccessLifecycleReasonRequest $request,
        PlatformAccessMembership $platformAccessMembership,
        ChangePlatformAccessStatus $action,
    ): PlatformAccessMembershipResource {
        return $this->transition($request, $platformAccessMembership, $action, PlatformAccessStatus::Active);
    }

    public function deactivate(
        PlatformAccessLifecycleReasonRequest $request,
        PlatformAccessMembership $platformAccessMembership,
        ChangePlatformAccessStatus $action,
    ): PlatformAccessMembershipResource {
        return $this->transition($request, $platformAccessMembership, $action, PlatformAccessStatus::Deactivated);
    }

    public function revokeSessions(
        PlatformAccessLifecycleReasonRequest $request,
        PlatformAccessMembership $platformAccessMembership,
        RevokePlatformAdministratorSessions $action,
    ): JsonResponse {
        $this->authorize('manage', PlatformAccessMembership::class);

        /** @var User $actor */
        $actor = $request->user();

        $revoked = $action->handle($platformAccessMembership, (string) $request->validated('reason'), $actor);

        return response()->json([
            'data' => [
                'membership_id' => $platformAccessMembership->ulid,
                'sessions_revoked' => $revoked,
            ],
        ]);
    }

    private function transition(
        PlatformAccessLifecycleReasonRequest $request,
        PlatformAccessMembership $membership,
        ChangePlatformAccessStatus $action,
        PlatformAccessStatus $target,
    ): PlatformAccessMembershipResource {
        $this->authorize('manage', PlatformAccessMembership::class);

        /** @var User $actor */
        $actor = $request->user();

        $updated = $action->handle($membership, $target, (string) $request->validated('reason'), $actor);
        $updated->load(['user', 'permissionOverrides.permission']);

        return new PlatformAccessMembershipResource(
            $updated,
            $this->activeSessionCount($updated),
            $this->isMfaEnrolled($updated),
        );
    }

    /** A COUNT only — enough to decide whether to revoke, never a device or location history. */
    private function activeSessionCount(PlatformAccessMembership $membership): int
    {
        return SessionFamily::query()
            ->where('user_id', $membership->user_id)
            ->whereNull('revoked_at')
            ->count();
    }

    private function isMfaEnrolled(PlatformAccessMembership $membership): bool
    {
        $user = $membership->user;

        return $user !== null && $this->mfa->confirmedCredential($user) !== null;
    }
}
