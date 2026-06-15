<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Hr;

use App\Domain\Auth\Enums\PermissionOverrideEffect;
use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Services\PermissionOverrideService;
use App\Domain\Auth\Services\PermissionResolver;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StorePermissionOverrideRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Manage per-membership permission overrides (Plan §10.3). All authority,
 * grantability, anti-escalation, and auditing live in PermissionOverrideService;
 * this controller only resolves the target membership (404 cross-merchant, no
 * existence leak) and returns the resolved set after the change.
 */
final class PermissionOverrideController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionOverrideService $service,
        private readonly PermissionResolver $resolver,
    ) {}

    public function store(StorePermissionOverrideRequest $request, StaffProfile $staff): JsonResponse
    {
        $membership = $this->resolveMembership($staff);

        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();

        $this->service->set(
            $actor,
            $membership,
            (string) $validated['permission'],
            PermissionOverrideEffect::from((string) $validated['effect']),
            isset($validated['reason']) ? (string) $validated['reason'] : null,
        );

        return new JsonResponse(['data' => [
            'membership' => $membership->ulid,
            'permissions' => $this->resolver->forMembership($membership->refresh()),
        ]]);
    }

    public function destroy(StaffProfile $staff, Permission $permission): JsonResponse
    {
        $membership = $this->resolveMembership($staff);

        /** @var User $actor */
        $actor = request()->user();

        $this->service->revoke($actor, $membership, $permission->key);

        return new JsonResponse(['data' => [
            'membership' => $membership->ulid,
            'permissions' => $this->resolver->forMembership($membership->refresh()),
        ]]);
    }

    /** 404 a foreign-merchant staff profile (no leak), return its membership. */
    private function resolveMembership(StaffProfile $staff): MerchantUser
    {
        abort_if($staff->merchant_id !== $this->context->merchantId(), 404);

        /** @var MerchantUser|null $membership */
        $membership = $staff->merchantUser;
        abort_if($membership === null, 404);

        return $membership;
    }
}
