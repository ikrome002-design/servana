<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exceptions;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Services\InvoiceStateMachine;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invalid merchant-client-invoice state transition (Plan §25.3, guardrail §6.7;
 * Phase 17).
 *
 * Renders the Phase 3 envelope with the canonical `invalid_state_transition` code
 * (422). Status transitions go through the named domain actions /
 * {@see InvoiceStateMachine}; an unlisted transition is rejected here, never by a
 * silent no-op. The message is generic and safe (no internal ids).
 */
final class InvoiceStateException extends Exception
{
    public static function invalidTransition(InvoiceStatus $from, InvoiceStatus $to): self
    {
        return new self("An invoice cannot move from {$from->value} to {$to->value}.");
    }

    public static function reasonRequired(): self
    {
        return new self('A reason is required for this invoice action.');
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
