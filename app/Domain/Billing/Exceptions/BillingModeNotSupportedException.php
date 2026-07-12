<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fail-closed guard for non-fixed billing modes (Plan §50, §51, §52; Gate B5; Phase 20B).
 *
 * Phase 20B issues subscription invoices ONLY when the effective billing mode is `fixed_amount`. A
 * `percentage_on_merchant_client_invoice` or `fixed_amount_plus_percentage_on_merchant_client_invoice`
 * mode requires the percentage platform-fee ledger that does not exist until Phase 20E — so issuance
 * fails closed here (422 `billing_mode_not_supported`) and creates NO invoice, NO items, and NO
 * consumed sequence number, rather than issuing a silently-undercharged invoice.
 */
final class BillingModeNotSupportedException extends Exception
{
    public static function forMode(string $mode): self
    {
        return new self("Subscription invoices for billing mode '{$mode}' are not supported yet.");
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'billing_mode_not_supported',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], 422, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
