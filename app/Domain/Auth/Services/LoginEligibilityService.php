<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use App\Domain\Auth\Support\EligibilityResult;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * The seven Magic Link eligibility checks (Scope §2.3 / Plan §9.1).
 *
 * Called BOTH at request time (decide whether to send the email) and at consume
 * time (re-validate, because status may have changed). The result is uniform to
 * the caller; the specific denial reason is for audit only and is never exposed.
 *
 *   1 user exists by email                      ── ENFORCED here
 *   2 active membership in a merchant tenant
 *     (or is platform staff)                    ── DEFERRED → Phase 6
 *   3 user.status = active                       ── ENFORCED here
 *   4 merchant_users.status = active             ── DEFERRED → Phase 6
 *   5 user not suspended (merchant/platform)     ── ENFORCED here (user level)
 *   6 branch assignment for branch-scoped roles  ── DEFERRED → Phase 7
 *   7 token valid/unused/unexpired               ── enforced at consume time by
 *                                                   MagicLinkTokenService
 *
 * DEFERRAL CONTRACT: checks 2/4/6 depend on the merchant tenancy schema
 * (merchants, merchant_users, merchant_branches, branch_user_assignments) which
 * is owned by Phases 6–7 and does not exist yet. Enforcing them now would make
 * every login impossible, so they are gated behind the
 * `servana.auth.enforce_tenancy_eligibility` flag (default false). Phase 6/7
 * implement the real lookups in the seam methods below and flip the flag — the
 * request/consume flow is untouched. See docs/PROGRESS.md Phase 6/7.
 */
final class LoginEligibilityService
{
    // Audit reason codes (never returned to the client).
    public const REASON_USER_NOT_FOUND = 'user_not_found';

    public const REASON_USER_INACTIVE = 'user_inactive';

    public const REASON_NO_ACTIVE_MEMBERSHIP = 'no_active_membership';

    public const REASON_NO_BRANCH_ASSIGNMENT = 'no_branch_assignment';

    public function check(string $email): EligibilityResult
    {
        $user = $this->findUser($email);

        // CHECK 1 — user exists by email.
        if ($user === null) {
            return EligibilityResult::denied(self::REASON_USER_NOT_FOUND);
        }

        // CHECKS 3 & 5 — user is active and not suspended/deactivated.
        if (! $user->isActive()) {
            return EligibilityResult::denied(self::REASON_USER_INACTIVE);
        }

        // CHECKS 2 & 4 — active merchant membership (or platform staff).
        if (! $this->hasActiveMembershipOrIsPlatformStaff($user)) {
            return EligibilityResult::denied(self::REASON_NO_ACTIVE_MEMBERSHIP);
        }

        // CHECK 6 — branch assignment where the role is branch-scoped.
        if (! $this->hasRequiredBranchAssignment($user)) {
            return EligibilityResult::denied(self::REASON_NO_BRANCH_ASSIGNMENT);
        }

        // CHECK 7 — token validity is enforced atomically at consume time.
        return EligibilityResult::eligible();
    }

    public function findUser(string $email): ?User
    {
        return User::query()
            ->where('email', Str::lower(trim($email)))
            ->first();
    }

    /**
     * CHECKS 2 & 4 (Phase 6). Not enforceable until the merchant_users / platform
     * staff schema exists; while the feature flag is off this passes. Phase 6
     * replaces the enforced branch with a real membership lookup for $user.
     */
    private function hasActiveMembershipOrIsPlatformStaff(User $user): bool
    {
        if (! $this->tenancyEligibilityEnforced()) {
            return true;
        }

        // Phase 6: return $user->activeMembership !== null || $user->is_platform_staff;
        return false;
    }

    /**
     * CHECK 6 (Phase 7). Not enforceable until branch_user_assignments exists;
     * while the feature flag is off this passes. Phase 7 replaces the enforced
     * branch with a real branch-scope lookup for $user.
     */
    private function hasRequiredBranchAssignment(User $user): bool
    {
        if (! $this->tenancyEligibilityEnforced()) {
            return true;
        }

        // Phase 7: return $user->hasActiveBranchAssignmentForRole();
        return false;
    }

    private function tenancyEligibilityEnforced(): bool
    {
        return (bool) Config::get('servana.auth.enforce_tenancy_eligibility', false);
    }
}
