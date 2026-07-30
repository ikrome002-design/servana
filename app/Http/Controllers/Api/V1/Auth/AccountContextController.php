<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Auth\Support\AuthAuditLogger;
use App\Domain\Sessions\Services\AccountContextResolver;
use App\Domain\Sessions\Services\ContextHandoffService;
use App\Domain\Sessions\Services\SessionFamilyService;
use App\Http\Controllers\Controller;
use App\Http\Hosts\AccountHost;
use App\Http\Hosts\AccountHostUrlGenerator;
use App\Http\Requests\Auth\SwitchAccountContextRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Account-context discovery and switching (Phase UI-03; ADR-018; UI/UX plan §5.3).
 *
 * AUTHORIZATION IS OWNERSHIP. Both actions operate strictly on the authenticated user's own
 * contexts, so no new permission key is introduced — the permission matrix governs CROSS-USER
 * administration, which UI-03 deliberately does not add (phase brief §21).
 *
 * The browser never names a role, a merchant, a branch, a host or an MFA state. It names an opaque
 * context id, and that id is validated by membership of the freshly derived list — a forged id
 * matches nothing.
 */
final class AccountContextController extends Controller
{
    /**
     * GET /auth/account-contexts — every context this user may enter right now.
     *
     * Only ACTIVE, authorized contexts appear. An inactive merchant, a suspended membership or a
     * branch-scoped role with no active assignment simply produces no entry: there is no
     * "listed but unusable" state for a caller to probe.
     */
    public function index(
        Request $request,
        AccountContextResolver $contexts,
        SessionFamilyService $families,
        AuthAuditLogger $audit,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $currentAccountKey = $request->hasSession()
            ? $families->findBySessionId($request->session()->getId())?->account_key
            : null;

        $payload = array_map(
            fn ($context): array => $context->toArray($context->accountKey === $currentAccountKey),
            $contexts->forUser($user),
        );

        $audit->record(AuditEvent::AccountContextsViewed, $user->email, null, $user->ulid);

        return response()->json(['data' => $payload]);
    }

    /**
     * POST /auth/account-contexts/switch — mint a single-use handoff and return the target URL.
     *
     * Returns only an ALLOWLISTED absolute URL built by AccountHostUrlGenerator from the registry.
     * The frontend never constructs a target host itself, so a compromised bundle cannot redirect
     * a user to a host Servana does not own.
     */
    public function switch(
        SwitchAccountContextRequest $request,
        AccountContextResolver $contexts,
        ContextHandoffService $handoffs,
        SessionFamilyService $families,
        AccountHostUrlGenerator $urls,
        AccountHost $host,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        if (! $request->hasSession()) {
            // A switch is a browser operation by definition; without a session there is no source
            // host session to bind the handoff to, and an unbound handoff is not a handoff.
            throw new HttpException(Response::HTTP_CONFLICT, 'A browser session is required to switch account contexts.');
        }

        $sourceHostSession = $families->findBySessionId($request->session()->getId());

        if ($sourceHostSession === null || ! $sourceHostSession->isActive()) {
            throw new HttpException(Response::HTTP_CONFLICT, 'This session can no longer switch account contexts.');
        }

        $target = $contexts->findByContextId($user, $request->contextId());

        if ($target === null) {
            // Non-enumerating: an id for a context that exists but is not this user's is
            // indistinguishable from an id that is pure noise.
            throw new HttpException(Response::HTTP_NOT_FOUND, 'Account context not found.');
        }

        if ($target->accountKey === $host->accountKey && $target->merchantUserId === $sourceHostSession->merchant_user_id) {
            throw new HttpException(Response::HTTP_CONFLICT, 'You are already in this account context.');
        }

        $targetUrl = $handoffs->issue(
            user: $user,
            sourceHostSession: $sourceHostSession,
            target: $target,
            // Validated here, at the boundary; an unsafe value is dropped, never carried.
            redirectPath: $urls->safeRelativePath($request->redirectPath()),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['data' => [
            'target_url' => $targetUrl,
            'target_account_key' => $target->accountKey,
            'requires_mfa' => $target->requiresMfa,
        ]], Response::HTTP_CREATED);
    }
}
