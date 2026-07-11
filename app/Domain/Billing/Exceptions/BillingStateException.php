<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invalid Phase-20A billing-configuration state transition (Plan §13.9, §13.10,
 * guardrail §6.7). Renders the canonical `invalid_state_transition` (422) envelope.
 * Status changes on `subscription_plans` and `preferred_personnel_fee_rules` go
 * through their named actions / state machines; an unlisted transition is rejected
 * here, never via a silent no-op. Messages are generic and safe (no internal ids).
 */
final class BillingStateException extends Exception
{
    public static function invalidTransition(string $aggregate, string $from, string $to): self
    {
        return new self("A {$aggregate} cannot move from {$from} to {$to}.");
    }

    public static function reasonRequired(): self
    {
        return new self('A reason is required for this action.');
    }

    public static function activeTermsImmutable(): self
    {
        return new self('Active monetary terms are immutable; supersede with a new version.');
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
