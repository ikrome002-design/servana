<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A queue business-rule conflict (Plan §37, §25.2; Phase 16B). Renders the Phase 3
 * error envelope with a stable, safe code and HTTP status — no internal ids,
 * SQLSTATE, or constraint names leak.
 *
 * Codes:
 *   - branch_day_not_open      (409) — the branch business day is not open.
 *   - queue_closed             (409) — the effective queue is closed.
 *   - queue_capacity_reached   (409) — the configured capacity is reached.
 *   - queue_conversion_exists  (409) — the walk-in/appointment already has an entry.
 *   - queue_order_changed      (409) — a stale reorder snapshot was submitted.
 *   - capacity_below_active     (422) — capacity set below the current active count.
 */
final class QueueConflictException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    public static function branchDayNotOpen(): self
    {
        return new self('branch_day_not_open', 'The branch business day must be open to use the queue.', 409);
    }

    public static function queueClosed(): self
    {
        return new self('queue_closed', 'The branch queue is closed.', 409);
    }

    public static function capacityReached(): self
    {
        return new self('queue_capacity_reached', 'The branch queue is at capacity.', 409);
    }

    public static function conversionExists(): self
    {
        return new self('queue_conversion_exists', 'This client is already on the queue.', 409);
    }

    public static function orderChanged(): self
    {
        return new self('queue_order_changed', 'The queue order changed; reload and try again.', 409);
    }

    public static function capacityBelowActive(): self
    {
        return new self('capacity_below_active', 'Queue capacity cannot be set below the current number of active entries.', 422);
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
