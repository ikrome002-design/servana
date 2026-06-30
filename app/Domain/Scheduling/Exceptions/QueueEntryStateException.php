<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Exceptions;

use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Services\QueueEntryStateMachine;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invalid queue-entry state transition (Plan §25.2, guardrail §6.7; Phase 16B).
 *
 * Renders the Phase 3 envelope with the canonical `invalid_state_transition`
 * code (422). Status transitions go through the named domain actions /
 * {@see QueueEntryStateMachine}; an unlisted transition is rejected here, never by
 * a silent no-op. The message is generic and safe (no internal ids).
 */
final class QueueEntryStateException extends Exception
{
    public static function invalidTransition(QueueEntryStatus $from, QueueEntryStatus $to): self
    {
        return new self("A queue entry cannot move from {$from->value} to {$to->value}.");
    }

    public static function reasonRequired(): self
    {
        return new self('A reason is required for this queue action.');
    }

    public static function sameTransferTarget(): self
    {
        return new self('Transfer the queue entry to a different personnel member or back to waiting.');
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
