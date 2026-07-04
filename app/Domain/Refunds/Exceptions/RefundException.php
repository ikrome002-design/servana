<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Exceptions;

use App\Domain\Refunds\Enums\RefundStatus;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * External-refund business-rule failures (Plan §44; Phase 18B). Renders the Phase 3
 * error envelope with a canonical, safe code; never leaks a SQLSTATE, constraint name,
 * external reference, or internal id. Any failure rolls the refund transaction back.
 */
final class RefundException extends Exception
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidTransition(RefundStatus $from, RefundStatus $to): self
    {
        return new self('invalid_state_transition', "A refund cannot move from {$from->value} to {$to->value}.", 422);
    }

    /** The refund requester may not approve or finalize their own request. */
    public static function makerIsChecker(): self
    {
        return new self('maker_is_checker', 'The refund requester may not approve or finalize the same refund.', 403);
    }

    /** The component is not a validated payment, so it cannot be refunded. */
    public static function componentNotValidated(): self
    {
        return new self('refund_component_not_validated', 'Only a validated payment component can be refunded.', 422);
    }

    /** The requested amount exceeds the component's remaining refundable validated amount. */
    public static function exceedsRefundable(): self
    {
        return new self('refund_exceeds_refundable', 'The refund amount exceeds the remaining refundable amount for this payment.', 422);
    }

    public static function currencyMismatch(): self
    {
        return new self('mixed_currency', 'The refund currency must match the payment currency.', 422);
    }

    public static function referenceRequired(): self
    {
        return new self('refund_reference_required', 'This refund method requires an external reference.', 422);
    }

    /** The refund would push the invoice recognised balance outside 0..total. */
    public static function balanceOutOfRange(): self
    {
        return new self('refund_balance_out_of_range', 'Finalizing this refund would put the invoice balance out of range.', 422);
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], $this->status, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
