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
 *     (or is platform staff)                    ── ENFORCED here (Phase 6)
 *   3 user.status = active                       ── ENFORCED here
 *   4 merchant_users.status = active             ── ENFORCED here (Phase 6)
 *   5 user not suspended (merchant/platform)     ── ENFORCED here (user level)
 *   6 branch assignment for branch-scoped roles  ── ENFORCED here (Phase 7)
 *   7 token valid/unused/unexpired               ── enforced at consume time by
 *                                                   MagicLinkTokenService
 *
 * PHASE 6: checks 2 & 4 — a single active `merchant_users` row, OR platform-staff
 * status, is required (User::hasTenantAccess).
 *
 * PHASE 7: check 6 — a branch-scoped role (everything except merchant_admin)
 * requires at least one active branch_user_assignment. Merchant Admin is exempt
 * (sees all own-merchant branches by role). All tenancy checks (2/4/6) are gated
 * by `servana.auth.enforce_tenancy_eligibility` so an environment can disable
 * gating for diagnostics. See docs/PROGRESS.md.
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
     * CHECKS 2 & 4 (Phase 6). Real lookup: an active merchant membership, OR
     * platform-staff status. While the feature flag is off this passes (so an
     * environment can disable tenancy gating for diagnostics).
     */
    private function hasActiveMembershipOrIsPlatformStaff(User $user): bool
    {
        if (! $this->tenancyEligibilityEnforced()) {
            return true;
        }

        return $user->hasTenantAccess();
    }

    /**
     * CHECK 6 (Phase 7). A branch-scoped role requires at least one active
     * branch_user_assignment; Merchant Admin (and platform staff) are exempt.
     * Gated by the same flag as checks 2 & 4.
     */
    private function hasRequiredBranchAssignment(User $user): bool
    {
        if (! $this->tenancyEligibilityEnforced()) {
            return true;
        }

        if ($user->is_platform_staff) {
            return true;
        }

        $membership = $user->activeMembership();

        // No active membership is already caught by checks 2 & 4; nothing to add.
        if ($membership === null) {
            return true;
        }

        // Merchant Admin sees all own-merchant branches by role — no assignment needed.
        if (! $membership->isBranchScoped()) {
            return true;
        }

        return $membership->hasActiveBranchAssignment();
    }

    private function tenancyEligibilityEnforced(): bool
    {
        return (bool) Config::get('servana.auth.enforce_tenancy_eligibility', false);
    }
}
