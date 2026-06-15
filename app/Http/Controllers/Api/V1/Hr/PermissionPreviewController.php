<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Hr;

use App\Domain\Auth\Models\MerchantUserPermissionOverride;
use App\Domain\Auth\Services\PermissionRegistry;
use App\Domain\Auth\Services\PermissionResolver;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * HR / Merchant Admin permission preview (Plan §10.3).
 *
 *   - `show`    → the resolved permissions + active overrides of one staff member.
 *   - `preview` → what a target ROLE would hold (defaults + grantable extras),
 *     so HR/admin can reason about a change BEFORE applying it.
 *
 * Both are read-only and gated to actors who may manage staff — preview never
 * mutates and so can never be a self-escalation vector.
 */
final class PermissionPreviewController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionRegistry $registry,
        private readonly PermissionResolver $resolver,
    ) {}

    public function show(StaffProfile $staff): JsonResponse
    {
        abort_if($staff->merchant_id !== $this->context->merchantId(), 404);

        /** @var MerchantUser|null $membership */
        $membership = $staff->merchantUser;
        abort_if($membership === null, 404);

        $this->authorize('viewPermissions', $membership);

        return new JsonResponse(['data' => $this->describeMembership($membership)]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->assertCanManageStaff();

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in($this->merchantRoleKeys())],
        ]);

        $roleKey = (string) $validated['role'];

        return new JsonResponse(['data' => [
            'role' => $roleKey,
            'default_grants' => $this->registry->defaultGrantsFor($roleKey),
            'grantable' => $this->registry->grantableFor($roleKey),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeMembership(MerchantUser $membership): array
    {
        $overrides = MerchantUserPermissionOverride::query()
            ->where('merchant_user_id', $membership->id)
            ->with('permission')
            ->get()
            ->map(static fn (MerchantUserPermissionOverride $o): array => [
                'permission' => $o->permission?->key,
                'effect' => $o->effect->value,
                'reason' => $o->reason,
            ])
            ->all();

        return [
            'membership' => $membership->ulid,
            'role' => $membership->role->value,
            'permissions' => $this->resolver->forMembership($membership),
            'default_grants' => $this->registry->defaultGrantsFor($membership->role->value),
            'grantable' => $this->registry->grantableFor($membership->role->value),
            'overrides' => $overrides,
        ];
    }

    /** Preview is for staff managers (HR or Merchant Admin) only. */
    private function assertCanManageStaff(): void
    {
        if (! $this->context->can('staff.invite') && ! $this->context->can('branches.manage_users_lifecycle')) {
            throw new AccessDeniedHttpException('This action is unauthorized.');
        }
    }

    /** @return list<string> */
    private function merchantRoleKeys(): array
    {
        return array_map(static fn (MerchantUserRole $r): string => $r->value, MerchantUserRole::cases());
    }
}
