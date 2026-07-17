<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invalid Phase-20F compensation-configuration state transition (Plan §59; guardrail §6.7).
 * Renders the canonical `invalid_state_transition` (422) envelope. There is no generic
 * `PATCH status` route and no DELETE route — every transition runs through its named action and
 * state machine, and an unlisted pair is rejected here, never via a silent no-op.
 *
 * Messages are generic and safe: no SQLSTATE, no constraint name, no internal id, no stack trace.
 */
final class CompensationStateException extends Exception
{
    public static function invalidTransition(string $aggregate, string $from, string $to): self
    {
        return new self("A {$aggregate} cannot move from {$from} to {$to}.");
    }

    public static function reasonRequired(): self
    {
        return new self('A change reason is required for this action.');
    }

    /** F7: once a plan/rule leaves draft its terms are immutable — supersede, never edit. */
    public static function termsImmutable(): self
    {
        return new self('Effective compensation terms are immutable; supersede with a new version.');
    }

    /** The scheduled → active boundary has not been reached yet. */
    public static function activationBoundaryNotReached(): self
    {
        return new self('This plan is not effective yet and cannot be activated.');
    }

    /** The active → expired boundary has not been reached yet. */
    public static function expiryBoundaryNotReached(): self
    {
        return new self('This plan is still within its effective window and cannot be expired.');
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'invalid_state_transition',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], 422, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
