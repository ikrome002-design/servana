<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Exceptions;

use App\Domain\FinanceOps\Enums\FinanceExportStatus;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finance export request/lifecycle failures (Plan §65, §67; Gate I; Phase 18B).
 * Renders the Phase 3 error envelope with a canonical, safe code; never leaks a
 * SQLSTATE, storage path, signed URL, or export contents.
 */
final class FinanceExportException extends Exception
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    /** The requested export type is not yet supported this phase (compensation/payouts/billing). */
    public static function unsupportedType(): self
    {
        return new self('unsupported_export_type', 'That finance export type is not available yet.', 422);
    }

    public static function invalidTransition(FinanceExportStatus $from, FinanceExportStatus $to): self
    {
        return new self('invalid_state_transition', "A finance export cannot move from {$from->value} to {$to->value}.", 422);
    }

    /** The export is not in a downloadable (ready) state. */
    public static function notDownloadable(): self
    {
        return new self('finance_export_not_ready', 'This finance export is not available for download.', 409);
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
