<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Auth\Actions\ConsumeMagicLink;
use App\Domain\Auth\Actions\RequestMagicLink;
use App\Domain\Auth\Enums\AuthEvent;
use App\Domain\Auth\Exceptions\InvalidMagicLinkException;
use App\Domain\Auth\Support\AuthEventLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestMagicLinkRequest;
use App\Http\Requests\Auth\VerifyMagicLinkRequest;
use App\Http\Resources\Auth\AuthenticatedUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Magic Link authentication (Plan §9.1, §9.2). All three actions thin:
 * validate → delegate to the Domain action → shape the response.
 */
final class MagicLinkController extends Controller
{
    /**
     * POST /auth/magic-link — always 202, never reveals account existence.
     */
    public function request(RequestMagicLinkRequest $request, RequestMagicLink $action): JsonResponse
    {
        $action->handle($request->email(), $request->ip(), $request->userAgent());

        return response()->json([
            'message' => 'If the email exists and is active, a link was sent.',
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * POST /auth/magic-link/verify — atomic consume, then session login with id
     * regeneration (fixation defense). Uniform 422 on any failure.
     */
    public function verify(VerifyMagicLinkRequest $request, ConsumeMagicLink $action): JsonResponse
    {
        $user = $action->handle($request->token());

        if ($user === null) {
            throw new InvalidMagicLinkException;
        }

        Auth::guard('web')->login($user);
        // Regenerate the session id on login — fixation defense (Plan §9.2).
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return AuthenticatedUserResource::make($user)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * POST /auth/logout — invalidate the session and rotate the CSRF token.
     */
    public function logout(Request $request, AuthEventLogger $audit): Response
    {
        $user = $request->user();

        // Tear down the stateful session (Plan §9.2). Token-only requests have no
        // session, so guard the session calls to stay robust either way.
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $audit->record(AuthEvent::Logout, null, null, $user?->getAttribute('ulid'));

        return response()->noContent();
    }
}
