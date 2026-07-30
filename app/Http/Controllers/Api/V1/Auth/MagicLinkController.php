<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Auth\Actions\ConsumeMagicLink;
use App\Domain\Auth\Actions\RequestMagicLink;
use App\Domain\Auth\Exceptions\InvalidMagicLinkException;
use App\Domain\Auth\Mfa\MfaRequirementResolver;
use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Domain\Auth\Support\AuthAuditLogger;
use App\Domain\Sessions\Enums\SessionRevocationReason;
use App\Domain\Sessions\Services\SessionFamilyService;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextResolver;
use App\Http\Controllers\Controller;
use App\Http\Hosts\AccountHost;
use App\Http\Requests\Auth\RequestMagicLinkRequest;
use App\Http\Requests\Auth\VerifyMagicLinkRequest;
use App\Http\Resources\Auth\AuthenticatedUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Host-bound Magic Link authentication (Plan §9.1, §9.2; ADR-018, ADR-019).
 *
 * Every action is thin: resolve the account host → delegate to the Domain action → shape the
 * response. `ResolveAccountHost` runs first on these routes, so an unapproved host is refused with
 * a safe 421 before any authentication work happens — and resolving a host still grants nothing
 * (ADR-017). The host here is an ANTI-SUBSTITUTION BINDING INPUT, never an authorization input.
 */
final class MagicLinkController extends Controller
{
    /**
     * POST /auth/magic-link — always 202, never reveals account existence.
     *
     * The response is identical whether the address is unknown, ineligible, or simply not a user
     * of THIS account. Telling a caller "that email exists, but not as Finance" would leak both
     * the account and the role.
     */
    public function request(RequestMagicLinkRequest $request, RequestMagicLink $action, AccountHost $host): JsonResponse
    {
        $action->handle(
            email: $request->email(),
            accountKey: $host->accountKey,
            host: $host->host,
            environment: $host->environment,
            redirectPath: $request->redirectPath(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json([
            'message' => 'If the email exists and is active, a link was sent.',
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * POST /auth/magic-link/verify — atomic bound consume, then session login with id
     * regeneration (fixation defense), then the session-family and host-session binding that make
     * cross-host revocation possible. Uniform 422 on any failure.
     */
    public function verify(
        VerifyMagicLinkRequest $request,
        ConsumeMagicLink $action,
        TenantContext $context,
        TenantContextResolver $resolver,
        SessionFamilyService $families,
        MfaRequirementResolver $mfa,
        AccountHost $host,
    ): JsonResponse {
        $result = $action->handle($request->token(), $host->accountKey, $host->host, $host->environment);

        if ($result === null) {
            throw new InvalidMagicLinkException;
        }

        $user = $result->user;

        Auth::guard('web')->login($user);
        // Regenerate the session id on login — fixation defense (Plan §9.2). Everything below
        // binds against the REGENERATED id, so a planted id is never the one recorded.
        if ($request->hasSession()) {
            $request->session()->regenerate();

            // A Magic Link login always starts a NEW family: this browser session did not exist a
            // moment ago, so there is no prior family it legitimately belongs to. Sessions are
            // joined to an existing family only by a context handoff (ADR-018).
            $family = $families->startFamily($user);

            $families->bindHostSession(
                family: $family,
                user: $user,
                sessionId: $request->session()->getId(),
                context: $result->context,
                host: $host->host,
                // EVIDENCE of the requirement at creation time. The link itself asserts NO MFA —
                // EnsurePrivilegedMfa still challenges a mandatory role on the next request
                // (Plan §18, §9.4 step 2).
                mfaRequired: $mfa->isRequired($user),
            );
        }

        // Verify runs outside the ResolveTenantContext middleware group, so
        // populate the context here too — the bootstrap payload must carry the
        // merchant/membership/setup state the SPA routes on (Plan §6.2, §8.1).
        $resolver->populate($context, $user);

        return AuthenticatedUserResource::make($user)
            ->additional(['meta' => [
                'redirect_path' => $result->redirectPath ?? $result->context->defaultRoute,
            ]])
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * POST /auth/logout — sign out of THIS host only.
     *
     * Sibling sessions in the same family are deliberately untouched: signing out of the Finance
     * host must not silently end a Personnel session the user is still working in. Ending every
     * session is a separate, explicit action (`/auth/logout-all`).
     */
    public function logout(
        Request $request,
        AuthAuditLogger $audit,
        MagicLinkTokenService $tokens,
        SessionFamilyService $families,
    ): Response {
        $user = $request->user();

        // Logout invalidates any unconsumed Magic Link for this identity (Plan
        // §79 R6): a link minted before sign-out cannot be used to silently
        // re-authenticate after it. Done before the session teardown so the
        // authenticated email is still available.
        $email = $user?->getAttribute('email');
        if (is_string($email)) {
            $tokens->invalidateUnconsumedForEmail($email);
        }

        // Tear down the stateful session (Plan §9.2). Token-only requests have no
        // session, so guard the session calls to stay robust either way.
        if ($request->hasSession()) {
            $hostSession = $families->findBySessionId($request->session()->getId());

            if ($hostSession !== null) {
                $families->revokeHostSession($hostSession, SessionRevocationReason::CurrentHostLogout);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $ulid = $user?->getAttribute('ulid');
        $audit->record(AuditEvent::Logout, null, null, is_string($ulid) ? $ulid : null);

        return response()->noContent();
    }
}
