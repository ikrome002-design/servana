<?php

declare(strict_types=1);

namespace App\Domain\Branches\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branch calendar-exception conflicts (REM-SCR-002B; Plan §7.2, Scope §3.3).
 *
 * Renders the Phase 3 error envelope with a canonical, safe code. `UNIQUE(branch_id, date,
 * type)` is a DB invariant, so a concurrent duplicate surfaces here as a deterministic 422
 * instead of a SQLSTATE-leaking 500 — the constraint stays the arbiter, the response stays safe.
 */
final class BranchCalendarException extends Exception
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function duplicate(string $date, string $type): self
    {
        return new self(
            'calendar_exception_exists',
            "This branch already has a {$type} exception on {$date}. Edit or remove it instead.",
            422,
        );
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
