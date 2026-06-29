<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Concerns;

use App\Domain\Scheduling\Exceptions\AppointmentScheduleConflictException;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Maps the PostgreSQL exclusion-constraint violation
 * (`appointments_personnel_no_overlap`, SQLSTATE 23P01) to the deterministic,
 * safe `409 appointment_schedule_conflict` envelope. The database constraint is
 * the final concurrency authority; application validation is best-effort, so any
 * write that establishes/changes an assigned-personnel reservation runs through
 * here. No SQLSTATE, constraint name, or other appointment's data is exposed.
 */
trait MapsScheduleConflict
{
    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     *
     * @throws AppointmentScheduleConflictException
     */
    private function mappingScheduleConflict(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $e) {
            if ($this->isExclusionViolation($e)) {
                throw AppointmentScheduleConflictException::forPersonnel($e);
            }

            throw $e;
        }
    }

    private function isExclusionViolation(Throwable $e): bool
    {
        // 23P01 = exclusion_violation. Match the SQLSTATE and/or our constraint name.
        $sqlState = ($e instanceof QueryException) ? ($e->getCode() === '23P01') : false;
        $message = $e->getMessage();

        return $sqlState || str_contains($message, 'appointments_personnel_no_overlap');
    }
}
