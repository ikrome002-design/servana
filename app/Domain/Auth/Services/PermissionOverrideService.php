<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Auth\Enums\PermissionOverrideEffect;
use App\Domain\Auth\Models\MerchantUserPermissionOverride;
use App\Domain\Auth\Models\Permission;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Creates/updates/revokes per-membership permission overrides (Plan §10.3),
 * enforcing the §10.2 authority boundaries and recording every change — and
 * every denied attempt — to the audit trail.
 *
 * Authority:
 *   - Merchant Admin (`branches.manage_users_lifecycle`) may delegate any key
 *     that is grantable (◐) for the target's role, merchant-wide.
 *   - HR (`staff.suspend`) may manage operational staff within its own branch
 *     scope, but may only grant a key it itself holds (anti-escalation).
 *   - Nobody may alter their OWN membership (anti-self-escalation).
 *   - The read-only `audit` role (and anyone without manage authority) is denied
 *     — the attempt is audited (`permission.write_denied`).
 */
final class PermissionOverrideService
{
    /** Operational roles HR may administer (never admin/manager/hr). */
    private const HR_MANAGEABLE_ROLES = [
        MerchantUserRole::Finance,
        MerchantUserRole::FrontOffice,
        MerchantUserRole::Personnel,
        MerchantUserRole::Audit,
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionRegistry $registry,
        private readonly AuditRecorder $audit,
    ) {}

    /** Create or update an override. Returns the persisted row. */
    public function set(
        User $actor,
        MerchantUser $target,
        string $permissionKey,
        PermissionOverrideEffect $effect,
        ?string $reason = null,
    ): MerchantUserPermissionOverride {
        $permission = $this->resolvePermission($permissionKey);

        $this->assertCanManage($actor, $target);

        if ($effect === PermissionOverrideEffect::Grant) {
            $this->assertGrantAllowed($actor, $target, $permissionKey);
        }

        $existing = MerchantUserPermissionOverride::query()
            ->where('merchant_user_id', $target->id)
            ->where('permission_id', $permission->id)
            ->first();

        $override = MerchantUserPermissionOverride::query()->updateOrCreate(
            ['merchant_user_id' => $target->id, 'permission_id' => $permission->id],
            ['effect' => $effect, 'granted_by' => $actor->id, 'reason' => $reason],
        );

        $this->audit->record(
            $existing !== null ? AuditEvent::PermissionOverrideUpdated : AuditEvent::PermissionOverrideCreated,
            $actor,
            $this->context->merchantId(),
            null,
            $target,
            [
                'permission' => $permissionKey,
                'effect' => $effect->value,
                'target_membership' => $target->ulid,
                'target_role' => $target->role->value,
            ],
        );

        return $override;
    }

    /** Revoke an existing override (returns to the role default). */
    public function revoke(User $actor, MerchantUser $target, string $permissionKey): void
    {
        $permission = $this->resolvePermission($permissionKey);

        $this->assertCanManage($actor, $target);

        $override = MerchantUserPermissionOverride::query()
            ->where('merchant_user_id', $target->id)
            ->where('permission_id', $permission->id)
            ->first();

        if ($override === null) {
            throw new NotFoundHttpException('Override not found.');
        }

        $override->delete();

        $this->audit->record(
            AuditEvent::PermissionOverrideRevoked,
            $actor,
            $this->context->merchantId(),
            null,
            $target,
            [
                'permission' => $permissionKey,
                'target_membership' => $target->ulid,
                'target_role' => $target->role->value,
            ],
        );
    }

    private function resolvePermission(string $permissionKey): Permission
    {
        $permission = Permission::query()->where('key', $permissionKey)->first();

        if ($permission === null) {
            throw new NotFoundHttpException('Unknown permission.');
        }

        return $permission;
    }

    /**
     * Authority + anti-self-escalation gate. Denied attempts are audited before
     * the 403 so the security event is never silent (guardrail §6.* / Plan §22).
     */
    private function assertCanManage(User $actor, MerchantUser $target): void
    {
        // Anti-self-escalation: nobody edits their own membership's permissions.
        if ($target->user_id === $actor->id) {
            $this->auditDenied($actor, $target, AuditEvent::PermissionOverrideDeniedSelfEscalation, 'self_escalation');
            throw new AccessDeniedHttpException('This action is unauthorized.');
        }

        if ($this->context->can('branches.manage_users_lifecycle')) {
            return;
        }

        if ($this->canHrManage($target)) {
            return;
        }

        // No manage authority (includes the read-only audit role) — audit + deny.
        $this->auditDenied($actor, $target, AuditEvent::PermissionWriteDenied, 'insufficient_permission');
        throw new AccessDeniedHttpException('This action is unauthorized.');
    }

    /**
     * A grant must target a key that is grantable (◐) for the target role; a
     * non-admin actor may only grant a key it itself holds (anti-escalation).
     */
    private function assertGrantAllowed(User $actor, MerchantUser $target, string $permissionKey): void
    {
        if (! $this->registry->isGrantableFor($target->role->value, $permissionKey)) {
            $this->auditDenied($actor, $target, AuditEvent::PermissionOverrideDeniedSelfEscalation, 'not_grantable', $permissionKey);
            throw new AccessDeniedHttpException('This permission is not grantable for the target role.');
        }

        $isAdmin = $this->context->can('branches.manage_users_lifecycle');
        if (! $isAdmin && ! $this->context->can($permissionKey)) {
            $this->auditDenied($actor, $target, AuditEvent::PermissionOverrideDeniedSelfEscalation, 'escalation', $permissionKey);
            throw new AccessDeniedHttpException('Cannot grant a permission you do not hold.');
        }
    }

    private function canHrManage(MerchantUser $target): bool
    {
        if (! $this->context->can('staff.suspend')) {
            return false;
        }

        if (! in_array($target->role, self::HR_MANAGEABLE_ROLES, true)) {
            return false;
        }

        $branchIds = $target->activeBranchIds();
        if ($branchIds === []) {
            return false;
        }

        foreach ($branchIds as $branchId) {
            if (! $this->context->canAccessBranch($branchId)) {
                return false;
            }
        }

        return true;
    }

    private function auditDenied(User $actor, MerchantUser $target, AuditEvent $event, string $reason, ?string $permissionKey = null): void
    {
        $this->audit->record(
            $event,
            $actor,
            $this->context->merchantId(),
            null,
            $target,
            array_filter([
                'reason' => $reason,
                'permission' => $permissionKey,
                'target_membership' => $target->ulid,
                'target_role' => $target->role->value,
                'actor_role' => $this->context->role()?->value,
            ], static fn ($v): bool => $v !== null),
        );
    }
}
