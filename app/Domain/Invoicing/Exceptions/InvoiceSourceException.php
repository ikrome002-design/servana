<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An invoice could not source a line from a service session (Gate A; Phase 17):
 * the session is not completed, belongs to a different client/branch/merchant, is
 * already invoiced, or the selected sources are inconsistent (mixed
 * branch/client/currency). Renders the canonical error envelope with a stable code
 * and an appropriate status (409 for already-invoiced, 422 otherwise). Messages are
 * generic and safe (no internal ids).
 */
final class InvoiceSourceException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function invalidSessionState(): self
    {
        return new self('invalid_source_session_state', 'Only a completed service session can be invoiced.', 422);
    }

    public static function alreadyInvoiced(): self
    {
        return new self('service_session_already_invoiced', 'This service has already been added to an invoice.', 409);
    }

    public static function inconsistentSources(): self
    {
        return new self('inconsistent_invoice_sources', 'All invoice sources must share the same client, branch, and currency.', 422);
    }

    public static function noSources(): self
    {
        return new self('invoice_requires_a_source', 'An invoice requires at least one completed service session.', 422);
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
