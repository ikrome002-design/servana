<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use App\Domain\Auth\Support\RevocationSummary;
use App\Domain\Hr\Enums\StaffInvitationStatus;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Central, idempotent credential-revocation service (Plan §79 R6, REM-SESS-001).
 *
 * One place that revokes everything that can keep a principal authenticated or
 * re-authenticatable after a suspension / deactivation:
 *
 *   - all database-backed sessions for the affected user(s) (immediate logout +
 *     server-side MFA assertions die with their session);
 *   - all Sanctum personal-access tokens for the affected user(s) (defence in
 *     depth — there is NO token-issuance surface today, see note below);
 *   - all unconsumed Magic Links for the affected identities;
 *   - all still-pending staff invitations in the affected scope.
 *
 * Invariants (guardrail §6.4):
 *   - no raw session id, token hash, Magic-Link value or invitation token is
 *     ever logged, audited or returned — only secret-free aggregate counts;
 *   - every entry point is idempotent (a second call revokes nothing and never
 *     errors or restores access);
 *   - the database remains the authorization source of truth — accepted
 *     invitations and consumed Magic Links are never rewritten;
 *   - each pass is wrapped in a transaction so partial revocation cannot persist.
 *
 * Sanctum note: the application is Sanctum SPA-mode only (first-party stateful
 * cookies; config/sanctum.php). `User` deliberately does NOT use `HasApiTokens`
 * and nothing calls `createToken()`, so `personal_access_tokens` is normally
 * empty. We still revoke against the table directly so the control is correct
 * the moment a token surface ever appears — without inventing one.
 */
final class AccessRevocationService
{
    public const CATEGORY_USER = 'user';

    public const CATEGORY_MEMBERSHIP = 'membership';

    public const CATEGORY_MERCHANT = 'merchant';

    public function __construct(private readonly MagicLinkTokenService $tokens) {}

    /**
     * Revoke every credential for a single user, across all tenants (user-level
     * suspension / deactivation). Pending invitations addressed to the user's
     * email in ANY merchant are revoked.
     */
    public function revokeForUser(User $user): RevocationSummary
    {
        return DB::transaction(function () use ($user): RevocationSummary {
            $sessions = $this->deleteSessions([$user->id]);
            $tokens = $this->revokeTokens([$user->id]);
            $links = $this->tokens->invalidateUnconsumedForEmail($user->email);
            $invitations = $this->revokePendingInvitations($user->email, null);
            $this->forgetAuthorizationCache($user);

            return new RevocationSummary(
                self::CATEGORY_USER,
                sessionsRevoked: $sessions,
                tokensRevoked: $tokens,
                magicLinksInvalidated: $links,
                invitationsRevoked: $invitations,
                usersAffected: 1,
            );
        });
    }

    /**
     * Revoke credentials for the user behind one membership (membership-level
     * suspension / deactivation). Pending invitations are revoked only within
     * the membership's own merchant; a membership in another merchant for the
     * same user is unaffected at the invitation level.
     */
    public function revokeForMembership(MerchantUser $membership): RevocationSummary
    {
        $user = $membership->user;

        if ($user === null) {
            return new RevocationSummary(self::CATEGORY_MEMBERSHIP);
        }

        return DB::transaction(function () use ($membership, $user): RevocationSummary {
            $sessions = $this->deleteSessions([$user->id]);
            $tokens = $this->revokeTokens([$user->id]);
            $links = $this->tokens->invalidateUnconsumedForEmail($user->email);
            $invitations = $this->revokePendingInvitations($user->email, $membership->merchant_id);
            $this->forgetAuthorizationCache($user);

            return new RevocationSummary(
                self::CATEGORY_MEMBERSHIP,
                sessionsRevoked: $sessions,
                tokensRevoked: $tokens,
                magicLinksInvalidated: $links,
                invitationsRevoked: $invitations,
                usersAffected: 1,
            );
        });
    }

    /**
     * Revoke credentials for every user attached to a merchant whose membership
     * is not deactivated (merchant-level suspension / deactivation). All pending
     * invitations in the merchant are revoked. Per-request access is already
     * denied by EnsureMerchantActive; this additionally tears down live sessions.
     */
    public function revokeForMerchant(Merchant $merchant): RevocationSummary
    {
        return DB::transaction(function () use ($merchant): RevocationSummary {
            $memberships = MerchantUser::query()
                ->where('merchant_id', $merchant->id)
                ->whereIn('status', [
                    MerchantUserStatus::Active->value,
                    MerchantUserStatus::Suspended->value,
                    MerchantUserStatus::Invited->value,
                ])
                ->with('user')
                ->get();

            /** @var list<int> $userIds */
            $userIds = $memberships
                ->map(static fn (MerchantUser $m): ?int => $m->user?->id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $sessions = $this->deleteSessions($userIds);
            $tokens = $this->revokeTokens($userIds);

            $links = 0;
            foreach ($memberships as $membership) {
                $email = $membership->user?->email;
                if ($email !== null) {
                    $links += $this->tokens->invalidateUnconsumedForEmail($email);
                }
                if ($membership->user !== null) {
                    $this->forgetAuthorizationCache($membership->user);
                }
            }

            // Revoke every still-pending invitation in the merchant (scope-wide).
            $invitations = StaffInvitation::query()
                ->where('merchant_id', $merchant->id)
                ->pending()
                ->update([
                    'status' => StaffInvitationStatus::Revoked->value,
                    'revoked_at' => now(),
                ]);

            return new RevocationSummary(
                self::CATEGORY_MERCHANT,
                sessionsRevoked: $sessions,
                tokensRevoked: $tokens,
                magicLinksInvalidated: $links,
                invitationsRevoked: $invitations,
                usersAffected: count($userIds),
            );
        });
    }

    /**
     * Delete all database-backed sessions for the given user ids. Returns the
     * number of rows removed. A no-op (returns 0) when the list is empty or the
     * rows are already gone — idempotent.
     *
     * @param  list<int>  $userIds
     */
    private function deleteSessions(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return DB::table('sessions')->whereIn('user_id', $userIds)->delete();
    }

    /**
     * Delete all Sanctum personal-access tokens owned by the given users. See
     * the class note: there is no issuance surface today, so this is normally a
     * zero-row no-op kept for defence in depth and forward-correctness.
     *
     * @param  list<int>  $userIds
     */
    private function revokeTokens(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->delete();
    }

    /**
     * Revoke still-pending invitations for an email. When $merchantId is null
     * the revocation spans all merchants (user-level); otherwise it is scoped to
     * the one merchant (membership-level).
     */
    private function revokePendingInvitations(string $email, ?int $merchantId): int
    {
        $query = StaffInvitation::query()
            ->where('email', $this->tokens->normalizeEmail($email))
            ->pending();

        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }

        return $query->update([
            'status' => StaffInvitationStatus::Revoked->value,
            'revoked_at' => now(),
        ]);
    }

    /**
     * Invalidate any persisted authorization cache for a user.
     *
     * There is intentionally NO cross-request authorization cache in this
     * codebase: TenantContextResolver re-resolves membership, role, branch ids
     * and the permission set from the database on every authenticated request
     * (PermissionResolver issues fresh queries; TenantContext caches per-request
     * only). So this is a documented no-op seam — the place a future persistent
     * permission cache MUST be invalidated. We do not add Redis caching here
     * (that isolation work is owned by R7); see docs/proof/phase-r6.md.
     */
    private function forgetAuthorizationCache(User $user): void
    {
        // No-op by design — see method docblock. Kept as the single, named
        // invalidation point so a future cache cannot be added without it.
    }
}
