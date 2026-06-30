<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Exceptions;

use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Services\ServiceSessionStateMachine;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invalid service-session state transition (Plan §25.2, guardrail §6.7; Phase 16C).
 *
 * Renders the Phase 3 envelope with the canonical `invalid_state_transition`
 * code (422). Status transitions go through the named domain actions /
 * {@see ServiceSessionStateMachine}; an unlisted transition is rejected here, never
 * by a silent no-op. The message is generic and safe (no internal ids).
 */
final class ServiceSessionStateException extends Exception
{
    public static function invalidTransition(ServiceSessionStatus $from, ServiceSessionStatus $to): self
    {
        return new self("A service session cannot move from {$from->value} to {$to->value}.");
    }

    public static function reasonRequired(): self
    {
        return new self('A reason is required to cancel a service session.');
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
