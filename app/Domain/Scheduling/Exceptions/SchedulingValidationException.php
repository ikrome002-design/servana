<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Exceptions;

use App\Domain\Scheduling\ValueObjects\SchedulingDecision;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A personnel scheduling-validation failure (Plan §80 Phase 15B).
 *
 * Thrown by PersonnelSchedulingValidator::ensure() from a denied
 * {@see SchedulingDecision}. Renders the Phase 3 error envelope (422) with the
 * decision's stable, safe code — no internal ids, no cross-tenant existence.
 * Phase 16A appointment actions call ensure() so a scheduling violation surfaces
 * the same canonical envelope.
 */
final class SchedulingValidationException extends Exception
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function fromDecision(SchedulingDecision $decision): self
    {
        return new self(
            (string) $decision->code,
            (string) $decision->message,
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
        ], 422, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
