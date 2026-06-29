<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Exceptions;

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Overlapping active appointment for the same assigned personnel member
 * (Plan §36; Phase 16A). The PostgreSQL exclusion constraint
 * `appointments_personnel_no_overlap` is the final concurrency authority; a
 * violation is mapped here to a deterministic `409 appointment_schedule_conflict`.
 *
 * The message is generic and safe — no SQLSTATE, constraint name, internal id, or
 * the other appointment's hidden data is ever exposed.
 */
final class AppointmentScheduleConflictException extends Exception
{
    public static function forPersonnel(?Throwable $previous = null): self
    {
        return new self('This personnel member already has an appointment during the requested time.', 0, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        $correlationId = (string) app(CorrelationId::class)->get();

        return response()->json([
            'error' => [
                'code' => 'appointment_schedule_conflict',
                'message' => $this->getMessage(),
                'fields' => (object) [],
                'meta' => (object) [],
            ],
        ], 409, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
