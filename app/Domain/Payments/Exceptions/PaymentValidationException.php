<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Group-validation business-rule failures (Plan §42; Phase 18B). Renders the Phase 3
 * error envelope with a canonical, safe code; never leaks a SQLSTATE, constraint name,
 * reference, or internal id. Any validation failure rolls the whole transaction back
 * (no validation event, no receipt, no number consumed, no commission handoff, no
 * success audit).
 */
final class PaymentValidationException extends Exception
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    /** The recomputed component sum no longer equals the stored group total. */
    public static function componentSumMismatch(): self
    {
        return new self('payment_total_mismatch', 'The group total no longer equals the sum of its component amounts.', 422);
    }

    /** Validating the group would push validated-paid outside 0..total (must roll back). */
    public static function validatedPaidOverflow(): self
    {
        return new self('validated_paid_overflow', 'Validating this group would exceed the invoice total; the payment cannot be validated.', 422);
    }

    /** A validated group must recognise a positive amount. */
    public static function nothingToValidate(): self
    {
        return new self('nothing_to_validate', 'A validated group must recognise a positive amount.', 422);
    }

    /** A reason is required to reject or request correction of a group. */
    public static function reasonRequired(): self
    {
        return new self('validation_reason_required', 'A reason is required for this decision.', 422);
    }

    /** A reference may only be corrected while the group is correction_required. */
    public static function notCorrectable(): self
    {
        return new self('group_not_correctable', 'A payment reference can only be corrected while the group is awaiting correction.', 422);
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
