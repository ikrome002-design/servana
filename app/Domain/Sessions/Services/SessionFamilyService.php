<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Services;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Auth\Services\AccessRevocationService;
use App\Domain\Auth\Support\AuthAuditLogger;
use App\Domain\Sessions\Enums\SessionRevocationReason;
use App\Domain\Sessions\Models\HostSession;
use App\Domain\Sessions\Models\SessionFamily;
use App\Domain\Sessions\Support\AccountContext;
use App\Http\Hosts\AccountHostRegistry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates and revokes session families and their host sessions (Phase UI-03; ADR-018; UI/UX §5.2).
 *
 * ADR-018 rejects a shared `.servana.ke` cookie because it makes per-host revocation impossible.
 * This service is what replaces it: cookies stay host-only, and the FAMILY is the thing global
 * logout, suspension, role removal and branch removal act on — once, for every host.
 *
 * IT IS NOT A SECOND REVOCATION SYSTEM. {@see AccessRevocationService}
 * remains the single entry point for lifecycle revocation; it delegates the session-family half of
 * the work here. Nothing else calls `revokeFamiliesForUser()` directly.
 *
 * Revoking a host session DELETES the underlying row in Laravel's `sessions` table. Because the
 * session driver is database-backed, that is what makes the browser unauthenticated on its very
 * next request — on every host at once, not just the one that asked.
 */
final class SessionFamilyService
{
    public function __construct(
        private readonly AccountHostRegistry $registry,
        private readonly AuthAuditLogger $audit,
    ) {}

    /** Start a new family for a fresh sign-in. */
    public function startFamily(User $user): SessionFamily
    {
        $family = SessionFamily::query()->create([
            'user_id' => $user->id,
            'environment' => $this->registry->environment(),
            'last_activity_at' => now(),
        ]);

        $this->audit->record(AuditEvent::SessionFamilyCreated, $user->email, null, $user->ulid);

        return $family;
    }

    /**
     * Bind a Laravel session to one account context inside a family.
     *
     * `$mfaRequired` is recorded as EVIDENCE of what the requirement resolver said at creation
     * time. It is never read as an assertion — the live assurance stays `mfa_verified_at` in the
     * Laravel session and is never copied across hosts (ADR-018 step 7).
     */
    public function bindHostSession(
        SessionFamily $family,
        User $user,
        string $sessionId,
        AccountContext $context,
        string $host,
        bool $mfaRequired,
    ): HostSession {
        return DB::transaction(function () use ($family, $user, $sessionId, $context, $host, $mfaRequired): HostSession {
            // A session id is unique in `sessions`, so a rebind (re-login into an existing browser
            // session) must replace the previous binding rather than collide with it.
            HostSession::query()->where('session_id', $sessionId)->delete();

            $hostSession = HostSession::query()->create([
                'session_family_id' => $family->id,
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'account_key' => $context->accountKey,
                'host' => $host,
                'environment' => $family->environment,
                'merchant_id' => $context->merchantId,
                'merchant_user_id' => $context->merchantUserId,
                'branch_id' => $context->branchId,
                'mfa_required_at_creation' => $mfaRequired,
                'last_activity_at' => now(),
            ]);

            $family->forceFill(['last_activity_at' => now()])->save();

            $this->audit->record(AuditEvent::HostSessionCreated, $user->email, $context->accountKey, $user->ulid);

            return $hostSession;
        });
    }

    /** The host session bound to a Laravel session id, or null when there is none. */
    public function findBySessionId(string $sessionId): ?HostSession
    {
        return HostSession::query()
            ->with('family')
            ->where('session_id', $sessionId)
            ->first();
    }

    /**
     * Follow a host session onto a REGENERATED Laravel session id.
     *
     * `host_sessions` is keyed by `session_id`, so any code that regenerates the session id without
     * telling us orphans the row: `findBySessionId()` then finds nothing, the session silently
     * stops being a known host session, and everything keyed off it — account switching, own-session
     * listing, single-session revocation — breaks for that user.
     *
     * Magic Link verify and handoff consume both regenerate and then bind, so they are safe by
     * construction. The MFA challenge regenerates at its own privilege boundary and must re-point
     * the existing row instead; that is what this exists for. Returns false when the old id owned
     * no host session (a token-only or pre-UI-03 session), which is not an error.
     */
    public function rebindSessionId(string $previousSessionId, string $newSessionId): bool
    {
        if ($previousSessionId === $newSessionId) {
            return false;
        }

        return HostSession::query()
            ->where('session_id', $previousSessionId)
            ->whereNull('revoked_at')
            ->update(['session_id' => $newSessionId, 'updated_at' => now()]) === 1;
    }

    /** Stamp activity on a session and its family. Cheap, and only when the value actually moves. */
    public function touch(HostSession $hostSession): void
    {
        $hostSession->forceFill(['last_activity_at' => now()])->save();
        SessionFamily::query()
            ->where('id', $hostSession->session_family_id)
            ->update(['last_activity_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Revoke ONE host session — ordinary sign-out, or an owner revoking a session from their list.
     * Sibling sessions in the same family are deliberately untouched.
     *
     * Idempotent: revoking an already-revoked session changes nothing and never errors.
     */
    public function revokeHostSession(HostSession $hostSession, SessionRevocationReason $reason): void
    {
        if ($hostSession->revoked_at !== null) {
            return;
        }

        DB::transaction(function () use ($hostSession, $reason): void {
            $hostSession->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ])->save();

            $this->deleteSessions([$hostSession->session_id]);
        });

        $this->audit->record(
            AuditEvent::HostSessionRevoked,
            $hostSession->user?->email,
            $reason->value,
            $hostSession->user?->ulid,
        );
    }

    /**
     * Revoke a whole family: every active host session, and every underlying database session.
     *
     * Returns the number of host sessions revoked. Idempotent — a second call revokes nothing,
     * errors on nothing, and restores nothing.
     */
    public function revokeFamily(
        SessionFamily $family,
        SessionRevocationReason $reason,
        ?User $revokedBy = null,
    ): int {
        return DB::transaction(function () use ($family, $reason, $revokedBy): int {
            /** @var Collection<int, HostSession> $sessions */
            $sessions = HostSession::query()
                ->where('session_family_id', $family->id)
                ->active()
                ->get();

            /** @var list<string> $sessionIds */
            $sessionIds = array_values($sessions->pluck('session_id')->map(static fn (string $id): string => $id)->all());

            HostSession::query()
                ->where('session_family_id', $family->id)
                ->active()
                ->update([
                    'revoked_at' => now(),
                    'revoked_reason' => $reason->value,
                    'updated_at' => now(),
                ]);

            $this->deleteSessions($sessionIds);

            if ($family->revoked_at === null) {
                $family->forceFill([
                    'revoked_at' => now(),
                    'revoked_reason' => $reason,
                    'revoked_by_user_id' => $revokedBy?->id,
                    // Bump so a writer that read this family before the revocation cannot
                    // resurrect it by writing stale state back.
                    'lifecycle_version' => $family->lifecycle_version + 1,
                ])->save();

                $this->audit->record(
                    AuditEvent::SessionFamilyRevoked,
                    $family->user?->email,
                    $reason->value,
                    $family->user?->ulid,
                );
            }

            return count($sessionIds);
        });
    }

    /**
     * Revoke every family a user holds. Called by AccessRevocationService for user-level
     * suspension/deactivation and by the logout-all endpoint.
     */
    public function revokeFamiliesForUser(User $user, SessionRevocationReason $reason, ?User $revokedBy = null): int
    {
        $revoked = 0;

        /** @var Collection<int, SessionFamily> $families */
        $families = SessionFamily::query()->where('user_id', $user->id)->active()->get();

        foreach ($families as $family) {
            $revoked += $this->revokeFamily($family, $reason, $revokedBy);
        }

        // Defence in depth: a host session whose family was already revoked (or which somehow lost
        // its family) still has to die. Revocation must not depend on the family row being tidy.
        $revoked += $this->revokeMatching(
            HostSession::query()->where('user_id', $user->id)->active(),
            $reason,
        );

        return $revoked;
    }

    /**
     * Revoke every active host session whose CONTEXT matches a narrower scope — one merchant, one
     * membership, or one branch — leaving the user's other contexts signed in.
     *
     * This is what makes "role removal revokes affected host sessions" (UI/UX §5.2) true without
     * over-revoking: a Personnel session in merchant A survives the removal of a Finance role in
     * merchant B.
     */
    public function revokeForMerchant(int $merchantId, SessionRevocationReason $reason): int
    {
        return $this->revokeMatching(
            HostSession::query()->where('merchant_id', $merchantId)->active(),
            $reason,
        );
    }

    public function revokeForMembership(int $merchantUserId, SessionRevocationReason $reason): int
    {
        return $this->revokeMatching(
            HostSession::query()->where('merchant_user_id', $merchantUserId)->active(),
            $reason,
        );
    }

    /**
     * Revoke branch-bound sessions.
     *
     * `$merchantUserId` narrows to ONE membership's sessions in that branch, which is what a
     * single assignment revocation means. Passing null (a branch being archived) revokes every
     * session bound to the branch. Over-revoking here would sign out an entire branch's staff
     * because one person's assignment changed.
     */
    public function revokeForBranch(int $branchId, SessionRevocationReason $reason, ?int $merchantUserId = null): int
    {
        $query = HostSession::query()->where('branch_id', $branchId)->active();

        if ($merchantUserId !== null) {
            $query->where('merchant_user_id', $merchantUserId);
        }

        return $this->revokeMatching($query, $reason);
    }

    /** @param  Builder<HostSession>  $query */
    private function revokeMatching(Builder $query, SessionRevocationReason $reason): int
    {
        return DB::transaction(function () use ($query, $reason): int {
            /** @var Collection<int, HostSession> $sessions */
            $sessions = $query->get();

            if ($sessions->isEmpty()) {
                return 0;
            }

            $ids = $sessions->pluck('id')->all();
            /** @var list<string> $sessionIds */
            $sessionIds = array_values($sessions->pluck('session_id')->map(static fn (string $id): string => $id)->all());

            HostSession::query()->whereIn('id', $ids)->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason->value,
                'updated_at' => now(),
            ]);

            $this->deleteSessions($sessionIds);

            return count($ids);
        });
    }

    /**
     * Delete the underlying Laravel session rows. This — not the `revoked_at` stamp — is what
     * actually logs the browser out, because the database is the session store.
     *
     * @param  list<string>  $sessionIds
     */
    private function deleteSessions(array $sessionIds): void
    {
        if ($sessionIds === []) {
            return;
        }

        DB::table('sessions')->whereIn('id', $sessionIds)->delete();
    }
}
