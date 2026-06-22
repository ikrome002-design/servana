<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\Exceptions\MfaChallengeRequiredException;
use App\Domain\Auth\Exceptions\MfaEnrollmentRequiredException;
use App\Domain\Auth\Mfa\MfaManager;
use App\Domain\Auth\Mfa\MfaRequirementResolver;
use App\Domain\Auth\Mfa\MfaSession;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandatory privileged-role MFA gate (Plan §18, §9.4 step 2; Phase R3).
 *
 * Runs IMMEDIATELY after authentication and BEFORE tenant context (pinned in
 * bootstrap/app.php priority, just ahead of ResolveTenantContext). For a user
 * whose role requires MFA (Super Administrator / Merchant Administrator /
 * Finance — resolved without TenantContext):
 *
 *   - no confirmed credential  → 403 `mfa_enrollment_required`
 *   - confirmed but no session assertion → 403 `mfa_challenge_required`
 *   - asserted this session    → continue (normal authorization still applies)
 *
 * Non-mandatory roles pass straight through. While MFA is incomplete only the
 * minimum bootstrap/recovery routes (status/enroll/confirm/challenge, /me,
 * logout) are allowed; ordinary privileged routes are not. MFA NEVER bypasses
 * tenant/branch/permission/policy checks — those run after this gate.
 */
final class EnsurePrivilegedMfa
{
    /** Routes permitted while a mandatory user's MFA is incomplete. */
    private const ALLOWLIST = [
        'me',
        'auth.logout',
        'auth.mfa.status',
        'auth.mfa.enroll',
        'auth.mfa.confirm',
        'auth.mfa.challenge',
        'auth.mfa.recovery-challenge',
    ];

    public function __construct(
        private readonly MfaRequirementResolver $resolver,
        private readonly MfaManager $manager,
        private readonly MfaSession $session,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // auth:sanctum runs first; a null user is handled there, not here.
        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $this->resolver->isRequired($user)) {
            return $next($request);
        }

        $allowed = $this->isAllowlisted($request);

        if (! $this->manager->isConfirmed($user)) {
            if ($allowed) {
                return $next($request);
            }

            throw new MfaEnrollmentRequiredException;
        }

        $asserted = $request->hasSession() && $this->session->isAsserted($request->session());

        if (! $asserted) {
            if ($allowed) {
                return $next($request);
            }

            throw new MfaChallengeRequiredException;
        }

        return $next($request);
    }

    private function isAllowlisted(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null && in_array($name, self::ALLOWLIST, true);
    }
}
