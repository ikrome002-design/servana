<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Merchant-client payment recording business-rule failures (Plan §41; Phase 18A).
 *
 * Renders the Phase 3 error envelope with a canonical, safe code. Never leaks a
 * SQLSTATE, constraint name, raw/normalized reference, or internal id. Validation
 * failures roll the recording transaction back (no durable evidence, no success
 * event). The duplicate-suspected outcome is NOT thrown — it is a committed,
 * durable hold returned to the controller (see PaymentRecordingResult) so the
 * idempotency layer caches the 409.
 */
final class PaymentRecordingException extends Exception
{
    /** @param array<string, mixed> $meta */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }

    public static function invoiceNotRecordable(): self
    {
        return new self('invoice_not_recordable', 'Payments can only be recorded against an issued or partially-paid invoice.', 422);
    }

    public static function emptyGroup(): self
    {
        return new self('empty_payment_group', 'A payment must contain at least one component.', 422);
    }

    public static function nonPositiveAmount(): self
    {
        return new self('invalid_payment_amount', 'Every payment component amount must be a positive value.', 422);
    }

    public static function mixedCurrency(): self
    {
        return new self('mixed_currency', 'Every component must use the same currency as the invoice.', 422);
    }

    public static function totalMismatch(): self
    {
        return new self('payment_total_mismatch', 'The group total must equal the sum of its component amounts.', 422);
    }

    public static function invalidComponentMethod(): self
    {
        return new self('invalid_component_method', 'A payment component must use a concrete method, not split_payment.', 422);
    }

    public static function referenceRequired(): self
    {
        return new self('payment_reference_required', 'This payment method requires a reference or evidence.', 422);
    }

    public static function invalidReferenceFormat(): self
    {
        return new self('invalid_payment_reference', 'The payment reference format is invalid for this method.', 422);
    }

    public static function overpayment(): self
    {
        return new self('payment_overpayment', 'The payment would exceed the invoice balance available to record.', 422);
    }

    /**
     * A durable, COMMITTED duplicate hold — the controller RETURNS `render()` (never
     * throws) so the group persists and idempotent replay caches the 409.
     *
     * @param  array<string, mixed>  $meta  group_id + method + masked matched reference
     */
    public static function duplicateSuspected(array $meta): self
    {
        return new self(
            'payment_reference_duplicate_suspected',
            'This payment reference matches an existing payment and needs Finance review before it can proceed.',
            409,
            $meta,
        );
    }

    public static function makerIsChecker(): self
    {
        return new self('maker_is_checker', 'The recording maker may not act as the checker for the same group.', 403);
    }

    public static function overrideReasonRequired(): self
    {
        return new self('override_reason_required', 'A reason is required to override a suspected duplicate reference.', 422);
    }

    public static function noDuplicateToOverride(): self
    {
        return new self('no_duplicate_to_override', 'There is no suspected duplicate to override for this reference check.', 422);
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => $this->meta === [] ? (object) [] : $this->meta,
            ],
        ], $this->status, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
