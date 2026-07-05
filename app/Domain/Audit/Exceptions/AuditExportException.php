<?php

declare(strict_types=1);

namespace App\Domain\Audit\Exceptions;

use App\Domain\Audit\Enums\AuditExportStatus;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Audit export request/lifecycle failures (Plan §13.5, §80; Phase 19; ADR-010).
 * Renders the Phase 3 error envelope with a canonical, safe code; never leaks a
 * SQLSTATE, storage path, signed URL, or export contents.
 */
final class AuditExportException extends Exception
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidTransition(AuditExportStatus $from, AuditExportStatus $to): self
    {
        return new self('invalid_state_transition', "An audit export cannot move from {$from->value} to {$to->value}.", 422);
    }

    /** The export is not in a downloadable (ready) state. */
    public static function notDownloadable(): self
    {
        return new self('audit_export_not_ready', 'This audit export is not available for download.', 409);
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
