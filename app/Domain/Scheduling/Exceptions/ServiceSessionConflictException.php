<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A service-session business-rule conflict (Plan §25.2; Phase 16C). Renders the
 * Phase 3 envelope with a stable, safe code — no internal id, SQLSTATE, or
 * constraint name leaks.
 *
 * Codes:
 *   - service_session_in_progress (409) — an in-progress, queue-linked session
 *     cannot be cancelled in Phase 16C (Gate C: the Queue Entry machine defines no
 *     `in_service → cancelled`; the only exit from an in-progress service is
 *     completion, until a future queue-machine extension lands).
 */
final class ServiceSessionConflictException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    public static function inProgressNotCancellable(): self
    {
        return new self(
            'service_session_in_progress',
            'An in-progress service session cannot be cancelled; complete it instead.',
            409,
        );
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], $this->status, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
