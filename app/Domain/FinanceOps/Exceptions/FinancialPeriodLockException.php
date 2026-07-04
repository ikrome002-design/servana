<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Exceptions;

use App\Domain\FinanceOps\Enums\FinancialPeriodLockStatus;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Financial period lock / reopen governance failures (Plan §46; ADR-0007; Phase 18B).
 * Renders the Phase 3 error envelope with a canonical, safe code; never leaks a
 * SQLSTATE, constraint name, or internal id. Distinct from
 * {@see FinancialPeriodLockedException} (the 423 raised when a MUTATION hits a locked
 * period) — this covers failures MANAGING the lock lifecycle.
 */
final class FinancialPeriodLockException extends Exception
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidTransition(FinancialPeriodLockStatus $from, FinancialPeriodLockStatus $to): self
    {
        return new self('invalid_state_transition', "A financial period lock cannot move from {$from->value} to {$to->value}.", 422);
    }

    public static function invalidRange(): self
    {
        return new self('invalid_period_range', 'The period start must be on or before the period end.', 422);
    }

    /** Another active lock already covers part of this scope + date range. */
    public static function overlapping(): self
    {
        return new self('overlapping_period_lock', 'An active lock already overlaps this period for the same scope.', 422);
    }

    /** A mandatory reopen reason is missing. */
    public static function reasonRequired(): self
    {
        return new self('period_reopen_reason_required', 'A reason is required to reopen a financial period.', 422);
    }

    /** The requester may not approve their own exceptional reopen. */
    public static function makerIsChecker(): self
    {
        return new self('maker_is_checker', 'The reopen requester may not approve the same exceptional reopen.', 403);
    }

    /** An exception-required reopen needs a distinct Merchant Administrator approval first. */
    public static function approvalRequired(): self
    {
        return new self('period_reopen_approval_required', 'This exceptional reopen requires a distinct Merchant Administrator approval before it can be executed.', 422);
    }

    /** Approval/execution attempted before a reopen was requested, or not exception-required. */
    public static function reopenNotRequested(): self
    {
        return new self('period_reopen_not_requested', 'No exceptional reopen has been requested for this period lock.', 422);
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
