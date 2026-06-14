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
 *   6 branch assignment for branch-scoped roles  ── DEFERRED → Phase 7
 *   7 token valid/unused/unexpired               ── enforced at consume time by
 *                                                   MagicLinkTokenService
 *
 * PHASE 6: the merchant tenancy schema (merchants, merchant_users) now exists,
 * so checks 2 & 4 are real — a single active `merchant_users` row, OR
 * platform-staff status, is required (User::hasTenantAccess). They are still
 * gated by `servana.auth.enforce_tenancy_eligibility` (now defaulting true) so
 * the behaviour can be toggled per environment.
 *
 * Check 6 (branch assignment) stays DEFERRED to Phase 7 regardless of the flag —
 * branch_user_assignments does not exist yet, so enforcing it would lock every
 * branch-scoped user out. hasRequiredBranchAssignment() therefore always passes
 * for now; Phase 7 wires the real branch-scope lookup. See docs/PROGRESS.md.
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
     * CHECK 6 (Phase 7). branch_user_assignments does not exist yet, so this
     * always passes — enforcing it now would lock out every branch-scoped user.
     * Phase 7 replaces the body with the real branch-scope lookup. Intentionally
     * NOT gated by the eligibility flag (the flag enables checks 2 & 4 today;
     * check 6 must remain inert until its schema lands).
     */
    private function hasRequiredBranchAssignment(User $user): bool
    {
        return true;
    }

    private function tenancyEligibilityEnforced(): bool
    {
        return (bool) Config::get('servana.auth.enforce_tenancy_eligibility', false);
    }
}
