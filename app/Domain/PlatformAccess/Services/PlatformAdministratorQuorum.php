<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Services;

use App\Domain\PlatformAccess\Enums\PlatformAccessStatus;
use App\Domain\PlatformAccess\Exceptions\PlatformAccessException;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Models\User;

/**
 * The lockout guard for internal platform access (COR-UI08-001 §11.5; Phase UI-08).
 *
 * Two rules, both enforced SERVER-SIDE inside the mutating transaction — a disabled button is not
 * enforcement:
 *
 *   1. An actor may never act on their own membership. Self-suspension, self-deactivation and
 *      self-permission-change are all refused, so an administrator can neither lock themselves out
 *      nor quietly widen their own access.
 *   2. A transition may never leave ZERO active Super Administrators. Removing the last one would
 *      lock the platform out of itself, and no route exists to grant the first one back.
 *
 * The count is taken with `lockForUpdate()` so two concurrent removals cannot each observe two
 * active administrators and both proceed. That race is the entire reason this is a service and not
 * an `if` in a controller.
 */
final class PlatformAdministratorQuorum
{
    /**
     * Assert that removing `$target` from the active set is safe, and that `$actor` is not the
     * target. Call inside the transaction, before the write.
     */
    public function assertRemovable(PlatformAccessMembership $target, User $actor): void
    {
        $this->assertNotSelf($target, $actor);

        if (! $target->isActive()) {
            // Removing an already-inactive membership cannot reduce the active count.
            return;
        }

        if ($this->activeCountForUpdate() <= 1) {
            throw PlatformAccessException::lastActiveAdministrator();
        }
    }

    /** An actor may never act on their own membership, whatever the action. */
    public function assertNotSelf(PlatformAccessMembership $target, User $actor): void
    {
        if ($target->user_id === $actor->id) {
            throw PlatformAccessException::selfActionForbidden();
        }
    }

    /**
     * Active administrators, counted under a row lock so a concurrent removal cannot slip past.
     *
     * The ids are SELECTed and counted in PHP rather than aggregated in SQL, for two reasons:
     * PostgreSQL rejects `FOR UPDATE` alongside an aggregate outright, and — more importantly — a
     * locking aggregate would not have locked the individual rows anyway. Selecting the ids takes a
     * real row lock on every active membership, which is what makes the check race-safe.
     */
    public function activeCountForUpdate(): int
    {
        /** @var list<int> $lockedIds */
        $lockedIds = PlatformAccessMembership::query()
            ->where('status', PlatformAccessStatus::Active->value)
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        return count($lockedIds);
    }
}
