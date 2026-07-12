<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A fixed-amount promotional discount was resolved for an invoice in a different currency (Plan §53;
 * Gate C5, point 6 — "enforce matching currency before calculation"). Servana bills in KES integer
 * minor units, so plan prices and fixed promotions are all KES; a mismatch indicates a configuration
 * defect and the calculation fails closed rather than applying a wrong-currency discount (never a
 * silent fallback). Renders the canonical `invalid_promotion_currency` (422) envelope.
 */
final class PromotionCurrencyMismatchException extends Exception
{
    public static function between(string $promotionCurrency, string $invoiceCurrency): self
    {
        return new self("A fixed promotional discount in {$promotionCurrency} cannot apply to a {$invoiceCurrency} invoice.");
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'invalid_promotion_currency',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], 422, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
