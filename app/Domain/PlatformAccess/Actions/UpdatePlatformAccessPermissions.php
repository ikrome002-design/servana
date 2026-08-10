<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Auth\Models\Permission;
use App\Domain\PlatformAccess\Exceptions\PlatformAccessException;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Domain\PlatformAccess\Models\PlatformAccessPermissionOverride;
use App\Domain\PlatformAccess\Services\PlatformAdministratorQuorum;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Replace an administrator's DENY overrides (COR-UI08-001 §11; Phase UI-08).
 *
 * DENY-ONLY. The request supplies the complete set of permission keys to deny; anything absent is
 * restored to the role default. There is no grant path, no grant column and no grant effect — an
 * override can only ever subtract from the `super_admin` defaults, which is what makes
 * self-escalation structurally impossible rather than merely policed.
 *
 * Three refusals, all server-side:
 *   - the actor may never change their OWN access (a "scope myself down" that later needs undoing
 *     is indistinguishable from an escalation attempt at review time);
 *   - a non-platform permission key is refused here AND by a database trigger;
 *   - an unknown key is refused rather than silently dropped.
 */
final class UpdatePlatformAccessPermissions
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformAdministratorQuorum $quorum,
    ) {}

    /**
     * @param  list<string>  $deniedPermissionKeys
     */
    public function handle(
        PlatformAccessMembership $membership,
        array $deniedPermissionKeys,
        string $reason,
        User $actor,
    ): PlatformAccessMembership {
        return DB::transaction(function () use ($membership, $deniedPermissionKeys, $reason, $actor): PlatformAccessMembership {
            /** @var PlatformAccessMembership $locked */
            $locked = PlatformAccessMembership::query()
                ->whereKey($membership->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->quorum->assertNotSelf($locked, $actor);

            $permissions = Permission::query()
                ->whereIn('key', $deniedPermissionKeys)
                ->get(['id', 'key', 'category']);

            foreach ($deniedPermissionKeys as $key) {
                $permission = $permissions->firstWhere('key', $key);

                if ($permission === null || $permission->category !== 'platform') {
                    throw PlatformAccessException::nonPlatformPermission($key);
                }
            }

            // Replace the whole set: absent keys return to the role default.
            $locked->permissionOverrides()->delete();

            foreach ($permissions as $permission) {
                PlatformAccessPermissionOverride::query()->create([
                    'platform_access_membership_id' => $locked->id,
                    'permission_id' => $permission->id,
                    'effect' => PlatformAccessPermissionOverride::EFFECT_DENY,
                    'reason' => $reason,
                    'created_by_user_id' => $actor->id,
                ]);
            }

            $locked->forceFill([
                'last_action' => 'permissions_changed',
                'last_action_reason' => $reason,
                'last_action_by_user_id' => $actor->id,
                'last_action_at' => now(),
            ])->save();

            $this->audit->record(AuditEvent::PlatformInternalAccessPermissionsChanged, $actor, null, null, $locked, [
                'membership_id' => $locked->ulid,
                'denied_permissions' => $permissions->pluck('key')->sort()->values()->all(),
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }
}
