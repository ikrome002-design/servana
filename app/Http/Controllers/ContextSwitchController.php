<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\Mfa\MfaRequirementResolver;
use App\Domain\Sessions\Services\ContextHandoffService;
use App\Domain\Sessions\Services\SessionFamilyService;
use App\Http\Hosts\AccountHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Consumes a context handoff on the TARGET host (Phase UI-03; ADR-018 steps 6–10).
 *
 * This is a BROWSER route, not an API route, and deliberately so: the browser must arrive at the
 * target host as a top-level navigation for the target's own host-only session cookie to be set.
 * It is documented outside OpenAPI for that reason (phase brief §20.4) and is guarded here
 * instead: ResolveAccountHost, a named rate limiter, single-use atomic consumption, session
 * regeneration, and a clean-URL redirect that strips the token immediately.
 *
 * SECRECY OF THE TOKEN IN TRANSIT:
 *  - `Referrer-Policy: no-referrer` so the token never leaks to any resource this response loads;
 *  - `Cache-Control: no-store` so no shared cache retains the URL;
 *  - a 302 to a token-free URL, so the address bar and browser history keep no copy;
 *  - nothing is written to `localStorage`/`sessionStorage`, and the token is never logged.
 *
 * FAILURE IS UNIFORM. Replay, expiry, wrong host, wrong environment, a revoked family, a removed
 * membership and a suspended user all produce the same redirect to the target account's sign-in
 * page. The browser cannot tell them apart; the audit trail can.
 */
final class ContextSwitchController extends Controller
{
    public function __invoke(
        Request $request,
        ContextHandoffService $handoffs,
        SessionFamilyService $families,
        MfaRequirementResolver $mfa,
        AccountHost $host,
    ): RedirectResponse {
        $token = $request->query('token');

        if (! is_string($token) || $token === '') {
            return $this->failed($host);
        }

        $result = $handoffs->consume($token, $host->accountKey, $host->host, $host->environment);

        if ($result === null) {
            return $this->failed($host);
        }

        $user = $result->user;

        Auth::guard('web')->login($user);

        if ($request->hasSession()) {
            // Fixation defence at the privilege boundary: the target session id must not be one
            // that existed before the switch (UI/UX plan §10.8).
            $request->session()->regenerate();

            $family = $result->sourceFamily;

            if ($family !== null) {
                $families->bindHostSession(
                    family: $family,
                    user: $user,
                    sessionId: $request->session()->getId(),
                    // The context rebuilt from CURRENT database state, not the one the token was
                    // minted with (ADR-018 step 7).
                    context: $result->context,
                    host: $host->host,
                    mfaRequired: $mfa->isRequired($user),
                );
            }

            // The source session's MFA assertion is deliberately NOT copied. The target session
            // starts with no privileged assurance, so EnsurePrivilegedMfa challenges whenever the
            // target role is mandatory — an unprivileged source can never mint a satisfied
            // privileged target (UI/UX plan §10.9, §17.3).
            $request->session()->forget('mfa_verified_at');
        }

        return redirect()
            ->to($result->redirectPath ?? $result->context->defaultRoute)
            ->withHeaders($this->tokenSafetyHeaders());
    }

    /**
     * Uniform failure: the target account's own sign-in page, on THIS host, with no detail.
     *
     * A relative path by construction, so the redirect can never leave the host the request
     * arrived on and can never move the user toward a broader account (UI/UX plan §5.4).
     */
    private function failed(AccountHost $host): RedirectResponse
    {
        return redirect()
            ->to('/auth/login?switch=failed')
            ->withHeaders($this->tokenSafetyHeaders());
    }

    /** @return array<string, string> */
    private function tokenSafetyHeaders(): array
    {
        return [
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ];
    }
}
