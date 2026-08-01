<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Auth\Services\MagicLinkTokenService;
use App\Domain\Auth\Support\AuthAuditLogger;
use App\Domain\Sessions\Enums\SessionRevocationReason;
use App\Domain\Sessions\Models\HostSession;
use App\Domain\Sessions\Services\SessionFamilyService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\HostSessionResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Own-session inspection and revocation (Phase UI-03; ADR-018; UI/UX plan §5.2).
 *
 * AUTHORIZATION IS OWNERSHIP, END TO END. Every query is filtered by `user_id` from the
 * authenticated principal — there is no route, parameter or permission that reaches another user's
 * sessions. Cross-user administrative session management would need a canonical permission
 * authority that does not exist today, and UI-03 does not invent one (phase brief §21).
 *
 * A foreign session ULID returns 404, not 403: telling a caller "that session exists but is not
 * yours" would confirm the existence of another user's session.
 */
final class HostSessionController extends Controller
{
    /** GET /auth/sessions — this user's active sessions, sanitized. */
    public function index(Request $request, SessionFamilyService $families): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;

        $sessions = HostSession::query()
            ->where('user_id', $user->id)
            ->active()
            ->with(['merchant', 'branch'])
            ->orderByDesc('last_activity_at')
            ->get();

        return response()->json([
            'data' => $sessions
                ->map(fn (HostSession $session): array => HostSessionResource::make($session)
                    ->currentSessionId($currentSessionId)
                    ->resolve($request))
                ->all(),
        ]);
    }

    /**
     * DELETE /auth/sessions/{hostSession} — revoke ONE of this user's sessions.
     *
     * Idempotent: revoking an already-revoked session succeeds and changes nothing.
     */
    public function destroy(Request $request, string $hostSession, SessionFamilyService $families): Response
    {
        /** @var User $user */
        $user = $request->user();

        $session = HostSession::query()
            ->where('user_id', $user->id)
            ->where('ulid', $hostSession)
            ->first();

        if ($session === null) {
            throw new NotFoundHttpException;
        }

        $families->revokeHostSession($session, SessionRevocationReason::SessionRevokedByOwner);

        return response()->noContent();
    }

    /**
     * POST /auth/logout-all — revoke every session in this user's families, on every host.
     *
     * This is the operation UI/UX plan §5.2 calls "global logout": it deletes every linked row in
     * Laravel's database session store, so the next request on ANY host is unauthenticated. It is
     * deliberately separate from `/auth/logout`, which ends only the current host's session.
     */
    public function destroyAll(
        Request $request,
        SessionFamilyService $families,
        MagicLinkTokenService $tokens,
        AuthAuditLogger $audit,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        // Unconsumed Magic Links die with the sessions (Plan §79 R6): otherwise a link minted
        // before the global logout would silently re-authenticate straight afterwards.
        $tokens->invalidateUnconsumedForEmail($user->email);

        $families->revokeFamiliesForUser($user, SessionRevocationReason::GlobalLogout, $user);

        $audit->record(AuditEvent::GlobalLogout, $user->email, null, $user->ulid);

        // The current session's row is already deleted above; tear down the local state too so the
        // responding request does not walk away still holding a live guard.
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
