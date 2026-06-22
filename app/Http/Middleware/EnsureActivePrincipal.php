<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request active-principal freshness gate (Plan §79 R6, REM-SESS-001).
 *
 * Runs immediately after authentication (auth:sanctum + EnforceIdleTimeout) and
 * BEFORE EnsurePrivilegedMfa / ResolveTenantContext. It proves, on EVERY
 * authenticated request, that the principal behind the session is still active
 * at the user level — for both merchant users and platform staff:
 *
 *   - user.status = active   → request proceeds
 *   - user suspended/deactiv → the live session is torn down and the request is
 *     rejected 401 (rendered as the structured `unauthenticated` envelope).
 *
 * AccessRevocationService already deletes a suspended user's sessions at
 * transition time, so the next request normally fails at auth:sanctum. This
 * middleware is the defence-in-depth backstop: a session that somehow survives
 * (a race, a future token surface, or a status flip that bypassed the lifecycle
 * service) can never carry a suspended/deactivated principal past this point.
 * It deliberately does NOT re-check membership/role/branch — those are resolved
 * fresh by ResolveTenantContext and gated by EnsureMerchantActive /
 * EnsureBranchScope downstream.
 */
final class EnsureActivePrincipal
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // auth:sanctum has already run; a null user means an unauthenticated
        // route reached us — leave the decision to the auth middleware.
        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->isActive()) {
            // Tear down the stateful session so the revoked principal cannot make
            // a further authenticated request, then deny uniformly with 401.
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            throw new AuthenticationException;
        }

        return $next($request);
    }
}
