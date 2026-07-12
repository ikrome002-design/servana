<?php

declare(strict_types=1);

namespace App\Domain\Merchants\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invalid merchant operational-status governance transition (Plan §22, §24.1; Phase 20B).
 * Renders the canonical `invalid_state_transition` (422) envelope. Operational-status changes
 * on `merchants.status` go through the named governance actions (SuspendMerchant /
 * ReactivateMerchant / DeactivateMerchant); an unlisted transition is rejected here, never via a
 * silent no-op. Messages are generic and safe (no internal ids, no raw reason).
 */
final class MerchantStatusException extends Exception
{
    public static function invalidTransition(string $from, string $to): self
    {
        return new self("A merchant cannot move from {$from} to {$to}.");
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
