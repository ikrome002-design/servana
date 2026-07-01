<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invalid payment-recording-group state transition (Plan §25, guardrail §6.7;
 * Phase 18A). Renders the canonical `invalid_state_transition` code (422). Group
 * status only ever changes through the named actions + the state machine; an
 * unlisted transition (including any Phase-18B transition attempted from a Phase
 * 18A path) is rejected here.
 */
final class PaymentGroupStateException extends Exception
{
    public static function invalidTransition(PaymentRecordingGroupStatus $from, PaymentRecordingGroupStatus $to): self
    {
        return new self("A payment recording group cannot move from {$from->value} to {$to->value}.");
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
