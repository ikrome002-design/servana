<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Uniform Magic Link verify failure (Plan §9.1): 422 `invalid_or_expired_token`.
 *
 * The same exception is thrown whether the token never existed, expired, was
 * already used, was invalidated, or the user is no longer eligible — the message
 * and status are identical in every case so nothing can be enumerated.
 *
 * It renders the Phase 3 envelope shape itself ({error:{code,message,fields,
 * meta}}) so it stays consistent without widening the cross-cutting ErrorCode
 * enum with an auth-specific code.
 */
final class InvalidMagicLinkException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'invalid_or_expired_token',
                'message' => 'This sign-in link is invalid or has expired. Please request a new one.',
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], 422, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
