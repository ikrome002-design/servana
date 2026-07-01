<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A financial mutation was attempted against a locked period (Plan §11.5, §46;
 * Gate C, Phase 17). Renders the canonical `financial_period_locked` envelope
 * (HTTP 423). The message is generic and safe (no internal ids).
 */
final class FinancialPeriodLockedException extends Exception
{
    public static function forPeriod(): self
    {
        return new self('This financial period is locked; the action cannot be completed.');
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'financial_period_locked',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], 423, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
