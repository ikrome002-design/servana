<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A personnel member already has an active service session, or the queue entry has
 * already produced one (Plan §13.7, §25.2; Phase 16C). The PostgreSQL partial-unique
 * `(staff_profile_id) WHERE status IN (pending,in_progress)` index and the
 * `UNIQUE (queue_entry_id)` index are the concurrency authority; a collision maps to
 * this stable `409` envelope. No SQLSTATE or constraint name leaks.
 */
final class DuplicateActiveSessionException extends Exception
{
    public function __construct(
        public readonly string $errorCode = 'duplicate_active_service_session',
        string $message = 'This personnel member already has an active service session.',
    ) {
        parent::__construct($message);
    }

    public static function forPersonnel(): self
    {
        return new self;
    }

    public static function forQueueEntry(): self
    {
        return new self('duplicate_active_service_session', 'This queue entry already has a service session.');
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
        ], 409, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
