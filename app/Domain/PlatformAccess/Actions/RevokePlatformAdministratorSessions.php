<?php

declare(strict_types=1);

namespace App\Domain\PlatformAccess\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\PlatformAccess\Models\PlatformAccessMembership;
use App\Domain\PlatformAccess\Services\PlatformAdministratorQuorum;
use App\Domain\Sessions\Enums\SessionRevocationReason;
use App\Domain\Sessions\Services\SessionFamilyService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoke another administrator's active sessions without changing their access (COR-UI08-001 §11.7;
 * Phase UI-08).
 *
 * REUSES THE R6 SESSION-FAMILY AUTHORITY. `SessionFamilyService::revokeFamiliesForUser()` is the
 * one revocation path; no second session store is created and no token material is read here. The
 * reason recorded is `platform_access_sessions_revoked`, added to the closed vocabulary by this
 * phase precisely because reusing `session_revoked_by_owner` or `global_logout` would have written a
 * FALSE forensic record — those mean the user acted on their own sessions.
 *
 * The membership itself is untouched: this is a "sign them out everywhere", not a status change.
 * Self-action is still refused, because an administrator revoking their own sessions is an ordinary
 * logout and belongs on the account surface, not on the governance one.
 */
final class RevokePlatformAdministratorSessions
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformAdministratorQuorum $quorum,
        private readonly SessionFamilyService $sessions,
    ) {}

    public function handle(PlatformAccessMembership $membership, string $reason, User $actor): int
    {
        return DB::transaction(function () use ($membership, $reason, $actor): int {
            /** @var PlatformAccessMembership $locked */
            $locked = PlatformAccessMembership::query()
                ->whereKey($membership->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->quorum->assertNotSelf($locked, $actor);

            // NOTE: SessionFamilyService::revokeFamiliesForUser() returns the number of HOST
            // SESSIONS it ended, not the number of families. Naming this "families_revoked" would
            // have been a quietly wrong number on a governance screen.
            $user = $locked->user;
            $revoked = $user === null
                ? 0
                : $this->sessions->revokeFamiliesForUser(
                    $user,
                    SessionRevocationReason::PlatformAccessSessionsRevoked,
                    $actor,
                );

            $locked->forceFill([
                'last_action' => 'sessions_revoked',
                'last_action_reason' => $reason,
                'last_action_by_user_id' => $actor->id,
                'last_action_at' => now(),
            ])->save();

            // No token, cookie or session id ever reaches the audit context — only counts and ULIDs.
            $this->audit->record(AuditEvent::PlatformInternalAccessSessionsRevoked, $actor, null, null, $locked, [
                'membership_id' => $locked->ulid,
                'sessions_revoked' => $revoked,
                'reason' => $reason,
            ]);

            return $revoked;
        });
    }
}
