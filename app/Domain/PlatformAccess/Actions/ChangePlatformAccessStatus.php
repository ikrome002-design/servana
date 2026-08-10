<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\PlatformAccess\Enums\PlatformAccessStatus;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Domain\PlatformAccess\Services\PlatformAccessStateMachine;
use App\Domain\PlatformAccess\Services\PlatformAdministratorQuorum;
use App\Domain\Sessions\Enums\SessionRevocationReason;
use App\Domain\Sessions\Services\SessionFamilyService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Suspend, reactivate or deactivate an internal platform administrator (COR-UI08-001 §11;
 * Phase UI-08). Lifecycle: docs/architecture/state-machines/platform-access-membership.md.
 *
 * ONE ACTION FOR THE THREE TRANSITIONS, because they share every safety obligation and splitting
 * them would mean maintaining the quorum check, the mirror write and the session revocation in
 * three places where they could drift apart. The controller still exposes three named routes — no
 * generic status endpoint exists (Plan §24.1).
 *
 * Every call, inside ONE transaction:
 *   1. locks the membership,
 *   2. refuses a self-action and refuses to leave zero active administrators,
 *   3. asserts the transition is legal,
 *   4. writes the membership,
 *   5. writes the DERIVED `users.is_platform_staff` mirror so the shipped eligibility, context and
 *      MFA paths stay correct,
 *   6. revokes session families when access is withdrawn — otherwise a suspended administrator
 *      would keep a live session until it happened to expire,
 *   7. audits.
 */
final class ChangePlatformAccessStatus
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformAccessStateMachine $stateMachine,
        private readonly PlatformAdministratorQuorum $quorum,
        private readonly SessionFamilyService $sessions,
    ) {}

    public function handle(
        PlatformAccessMembership $membership,
        PlatformAccessStatus $target,
        string $reason,
        User $actor,
    ): PlatformAccessMembership {
        return DB::transaction(function () use ($membership, $target, $reason, $actor): PlatformAccessMembership {
            /** @var PlatformAccessMembership $locked */
            $locked = PlatformAccessMembership::query()
                ->whereKey($membership->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Self-protection and lockout protection come BEFORE the transition check, so the
            // caller learns the real reason rather than a generic "invalid transition".
            if ($this->withdrawsAccess($target)) {
                $this->quorum->assertRemovable($locked, $actor);
            } else {
                $this->quorum->assertNotSelf($locked, $actor);
            }

            $this->stateMachine->assertCanTransition($locked->status, $target);

            $locked->forceFill([
                'status' => $target,
                'activated_at' => $target === PlatformAccessStatus::Active
                    ? ($locked->activated_at ?? now())
                    : $locked->activated_at,
                'suspended_at' => $target === PlatformAccessStatus::Suspended ? now() : null,
                'deactivated_at' => $target === PlatformAccessStatus::Deactivated ? now() : $locked->deactivated_at,
                'last_action' => $this->actionFor($target),
                'last_action_reason' => $reason,
                'last_action_by_user_id' => $actor->id,
                'last_action_at' => now(),
            ])->save();

            // The derived mirror, written in the same transaction so the two can never disagree.
            $locked->user()->update(['is_platform_staff' => $target->grantsAccess()]);

            if ($this->withdrawsAccess($target)) {
                // `membership_revoked` is the truthful existing reason: the membership backing the
                // context was suspended or removed.
                $user = $locked->user;

                if ($user !== null) {
                    $this->sessions->revokeFamiliesForUser($user, SessionRevocationReason::MembershipRevoked, $actor);
                }
            }

            $this->audit->record($this->auditEventFor($target), $actor, null, null, $locked, [
                'membership_id' => $locked->ulid,
                'status' => $target->value,
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }

    private function withdrawsAccess(PlatformAccessStatus $target): bool
    {
        return $target === PlatformAccessStatus::Suspended
            || $target === PlatformAccessStatus::Deactivated;
    }

    private function actionFor(PlatformAccessStatus $target): string
    {
        return match ($target) {
            PlatformAccessStatus::Active => 'reactivated',
            PlatformAccessStatus::Suspended => 'suspended',
            PlatformAccessStatus::Deactivated => 'deactivated',
            PlatformAccessStatus::Invited => 'invited',
        };
    }

    private function auditEventFor(PlatformAccessStatus $target): AuditEvent
    {
        return match ($target) {
            PlatformAccessStatus::Active => AuditEvent::PlatformInternalAccessReactivated,
            PlatformAccessStatus::Suspended => AuditEvent::PlatformInternalAccessSuspended,
            PlatformAccessStatus::Deactivated => AuditEvent::PlatformInternalAccessDeactivated,
            PlatformAccessStatus::Invited => AuditEvent::PlatformInternalAccessInvited,
        };
    }
}
