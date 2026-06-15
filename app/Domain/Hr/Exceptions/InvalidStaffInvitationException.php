<?php

declare(strict_types=1);

namespace App\Domain\Hr\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Uniform staff-invitation acceptance failure (Scope §3.4): 422
 * `invalid_or_expired_invitation`.
 *
 * The same exception is thrown whether the token never existed, expired, was
 * already accepted, or was revoked — identical message + status so nothing is
 * enumerable (mirrors the Magic Link verify pattern).
 */
final class InvalidStaffInvitationException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'invalid_or_expired_invitation',
                'message' => 'This invitation is invalid or has expired. Please ask for a new one.',
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], 422, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
