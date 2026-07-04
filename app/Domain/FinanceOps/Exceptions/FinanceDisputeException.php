<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Exceptions;

use App\Domain\FinanceOps\Enums\FinanceDisputeStatus;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finance-dispute business-rule failures (Plan §44; Phase 18B). Renders the Phase 3
 * error envelope with a canonical, safe code.
 */
final class FinanceDisputeException extends Exception
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidTransition(FinanceDisputeStatus $from, FinanceDisputeStatus $to): self
    {
        return new self('invalid_state_transition', "A finance dispute cannot move from {$from->value} to {$to->value}.", 422);
    }

    /** A dispute must link an invoice and/or a payment record. */
    public static function linkageRequired(): self
    {
        return new self('dispute_linkage_required', 'A finance dispute must reference an invoice or a payment record.', 422);
    }

    /** A resolution note is required to resolve or reject a dispute. */
    public static function resolutionNoteRequired(): self
    {
        return new self('dispute_resolution_note_required', 'A resolution note is required to resolve or reject a dispute.', 422);
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
