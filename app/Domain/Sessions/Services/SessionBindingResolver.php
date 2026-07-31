<?php

declare(strict_types=1);

namespace App\Domain\Sessions\Services;

use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Sessions\Models\HostSession;
use App\Domain\Sessions\Support\SessionBinding;
use App\Http\Hosts\AccountHostResolver;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Decides which account a request's session is bound to (Phase UI-03; ADR-018).
 *
 * This is the SESSION/HOST boundary. It is the only place that compares the host a request arrived
 * on against the host a session was bound to, and it hands the tenant layer a neutral
 * {@see SessionBinding} — never a host, never a header, never a permission.
 *
 * That dependency direction is deliberate and enforced. `AccountHostDoesNotAuthorizeTest` forbids
 * any account-host reference inside the authorization layers (policies, tenant scopes, the
 * permission resolver, `ResolveTenantContext`), because a host that reaches an authorization
 * decision has become authorization — exactly what ADR-017 forbids. Comparing a request host to a
 * SERVER-CREATED binding is a different thing: an anti-substitution check on a credential, the same
 * category as Magic Link host binding (ADR-019) and handoff target binding (ADR-018). It belongs
 * here, beside them.
 *
 * WHY THIS EXISTS AT ALL. `merchant_users` is UNIQUE(merchant, user), so a user holding two
 * memberships holds them in two different MERCHANTS. Resolving the tenant with "the first active
 * membership" therefore makes the tenant independent of which account the session is actually in.
 * The UI-03 deployed-origin browser proof caught the consequence: after switching to the Audit
 * account of merchant B, `/api/v1/me` on `audit.servana.test` still reported merchant A and
 * merchant A's Front Office permissions.
 */
final class SessionBindingResolver
{
    public function __construct(
        private readonly SessionFamilyService $families,
        private readonly AccountHostResolver $hosts,
    ) {}

    /**
     * The binding this request is operating under.
     *
     * A request is a BOUND BROWSER ACCOUNT REQUEST when it carries a session and arrived on an
     * approved account host. Only those are required to present a host session; everything else
     * (machine hosts, unapproved hosts, token callers, queue work) is `absent()` and keeps the
     * repository's existing contract.
     */
    public function forRequest(Request $request, User $user): SessionBinding
    {
        if (! $request->hasSession()) {
            return SessionBinding::absent();
        }

        // Resolved here, and nowhere else. The value is never returned to the caller.
        $accountHost = $this->hosts->resolve($request);

        if ($accountHost === null) {
            // A machine host or an unapproved host. Not a browser account request, so the browser
            // account-binding rule does not apply to it.
            return SessionBinding::absent();
        }

        $hostSession = $this->families->findBySessionId($request->session()->getId());

        if ($hostSession === null) {
            // A browser account request that carries no server-created binding. Fail closed rather
            // than guess an account for it: guessing is the defect this resolver exists to remove.
            return SessionBinding::mismatch();
        }

        if (! $this->agrees($hostSession, $user, $accountHost->host, $accountHost->accountKey, $accountHost->environment)) {
            return SessionBinding::mismatch();
        }

        if ($hostSession->merchant_user_id === null) {
            // A platform session owns no merchant. Platform authority comes from the user record
            // itself, which the tenant layer already resolves.
            return SessionBinding::platform();
        }

        // Loaded FRESH: the row names the intended context, current state supplies the authority.
        $membership = MerchantUser::query()->with('merchant')->find($hostSession->merchant_user_id);

        if ($membership === null || $membership->user_id !== $user->id) {
            return SessionBinding::mismatch();
        }

        return SessionBinding::merchant($membership);
    }

    /**
     * Every fact the binding asserts must still hold, and must match the request that presented it.
     *
     * Host-only cookies already make host substitution impossible from a real browser — a cookie set
     * on `audit.servana.ke` is simply not sent anywhere else. This check is the server-side
     * counterpart, so the property does not depend on the client honouring cookie scope.
     */
    private function agrees(
        HostSession $hostSession,
        User $user,
        string $requestHost,
        string $requestAccountKey,
        string $requestEnvironment,
    ): bool {
        if ($hostSession->user_id !== $user->id || ! $hostSession->isActive()) {
            return false;
        }

        $family = $hostSession->family;

        if ($family === null || $family->user_id !== $user->id || ! $family->isActive()) {
            return false;
        }

        return $hostSession->host === $requestHost
            && $hostSession->account_key === $requestAccountKey
            && $hostSession->environment === $requestEnvironment
            && $family->environment === $hostSession->environment;
    }
}
