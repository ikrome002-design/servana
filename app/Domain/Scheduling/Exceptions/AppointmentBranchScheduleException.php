<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Exceptions;

use App\Domain\Scheduling\Services\AppointmentBranchScheduleValidator;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Support\CorrelationId;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appointment interval rejected by the branch operating calendar (Plan §36;
 * Phase 16A). Thrown by {@see AppointmentBranchScheduleValidator}.
 *
 * Renders the Phase 3 error envelope (422) with a stable, safe `errorCode`:
 *   - `branch_inactive`            branch is suspended/archived
 *   - `branch_closed`             calendar closure (holiday/special/emergency) or a closed weekday
 *   - `outside_branch_hours`      interval not fully inside operating hours
 *   - `crosses_closed_period`     interval overlaps a break / closed sub-period
 *   - `invalid_schedule_window`   interval crosses a business-date boundary / malformed
 *
 * No internal ids or cross-tenant existence are leaked.
 */
final class AppointmentBranchScheduleException extends Exception
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function branchInactive(): self
    {
        return new self('branch_inactive', 'This branch is not active.');
    }

    public static function branchClosed(): self
    {
        return new self('branch_closed', 'The branch is closed for the requested date.');
    }

    public static function outsideHours(): self
    {
        return new self('outside_branch_hours', 'The appointment is outside the branch operating hours.');
    }

    public static function crossesClosedPeriod(): self
    {
        return new self('crosses_closed_period', 'The appointment overlaps a period when the branch is closed.');
    }

    public static function invalidWindow(): self
    {
        return new self('invalid_schedule_window', 'The appointment time window is invalid.');
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
        ], 422, [CorrelationIdMiddleware::HEADER => $correlationId]);
    }
}
