<?php

declare(strict_types=1);

namespace App\Domain\Branches\Exceptions;

use App\Domain\Branches\Enums\CashUpStatus;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cash-up business-rule failures (Plan §45; Phase 18B). Renders the Phase 3 error
 * envelope with a canonical, safe code; never leaks a SQLSTATE, constraint name, or
 * internal id. Any failure rolls the cash-up transaction back. See
 * docs/architecture/state-machines/cash-up.md.
 */
final class CashUpException extends Exception
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidTransition(CashUpStatus $from, CashUpStatus $to): self
    {
        return new self('invalid_state_transition', "A cash-up cannot move from {$from->value} to {$to->value}.", 422);
    }

    /** The Branch Manager who submitted a cash-up may not approve/reject/correct it. */
    public static function makerIsChecker(): self
    {
        return new self('maker_is_checker', 'The cash-up submitter may not review, approve, or reject the same cash-up.', 403);
    }

    /** A reason is mandatory for a reject / request-correction decision. */
    public static function reasonRequired(): self
    {
        return new self('cash_up_reason_required', 'A reason is required for this cash-up decision.', 422);
    }

    /** A counted method is not a concrete payment method (never split_payment). */
    public static function invalidMethod(): self
    {
        return new self('cash_up_invalid_method', 'Cash-up counts must use a concrete payment method.', 422);
    }

    /** Counts may only be edited on a draft / correction_requested cash-up (no destructive overwrite). */
    public static function notEditable(): self
    {
        return new self('cash_up_not_editable', 'This cash-up can no longer be edited; a submitted, approved, or locked cash-up is not overwritten.', 422);
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
