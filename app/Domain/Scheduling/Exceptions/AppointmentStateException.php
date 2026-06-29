<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Exceptions;

use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Domain\Scheduling\Services\AppointmentStateMachine;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invalid appointment state transition (Plan §25.2, guardrail §6.7; Phase 16A).
 *
 * Renders the Phase 3 envelope with the canonical `invalid_state_transition`
 * code (422). Status transitions go through the named domain actions /
 * {@see AppointmentStateMachine}; an unlisted
 * transition is rejected here, never by a silent no-op. The message is generic
 * and safe (no internal ids).
 */
final class AppointmentStateException extends Exception
{
    public static function invalidTransition(AppointmentStatus $from, AppointmentStatus $to): self
    {
        return new self("An appointment cannot move from {$from->value} to {$to->value}.");
    }

    public static function reasonRequired(): self
    {
        return new self('A reason is required to cancel an appointment after check-in.');
    }

    public static function notTransferable(): self
    {
        return new self('Only an assigned, active appointment can be transferred.');
    }

    public static function sameTransferTarget(): self
    {
        return new self('Transfer the appointment to a different personnel member.');
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
