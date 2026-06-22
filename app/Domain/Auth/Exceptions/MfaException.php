<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base for MFA / step-up flow failures (Plan §11.5, §18; Phase R3).
 *
 * Each subclass maps to one structured outcome code so the SPA can route the
 * user (enrollment vs. challenge vs. step-up) without leaking anything sensitive.
 * Renders the cross-cutting envelope shape directly, exactly like
 * {@see InvalidMagicLinkException}.
 */
abstract class MfaException extends Exception
{
    /** Structured error code (Plan §11.5). */
    abstract public function errorCode(): string;

    /** HTTP status for this outcome. */
    abstract public function statusCode(): int;

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => $this->errorCode(),
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], $this->statusCode(), [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
