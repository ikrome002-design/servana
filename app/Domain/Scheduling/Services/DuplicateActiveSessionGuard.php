<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Exceptions\DuplicateActiveSessionException;
use App\Domain\Scheduling\Models\ServiceSession;
use Illuminate\Database\QueryException;

/**
 * Duplicate-active-session protection (Plan §13.7, §25.2; Phase 16C).
 *
 * The PostgreSQL partial-unique index `(staff_profile_id) WHERE status IN
 * (pending,in_progress)` and `UNIQUE (queue_entry_id)` are the FINAL concurrency
 * authority. Application validation ({@see ensureNoActiveSession()}) provides a
 * friendly pre-check under the queue lock; any insert that creates a session runs
 * through {@see mappingDuplicate()} so a database collision (two concurrent starts
 * racing past the pre-check) maps to the stable `409 duplicate_active_service_session`
 * envelope. No SQLSTATE or constraint name is exposed.
 */
final class DuplicateActiveSessionGuard
{
    /**
     * Friendly pre-check: reject when the personnel member already has an active
     * (pending/in_progress) session in this merchant.
     *
     * @throws DuplicateActiveSessionException
     */
    public function ensureNoActiveSession(int $merchantId, int $staffProfileId): void
    {
        $exists = ServiceSession::query()
            ->where('merchant_id', $merchantId)
            ->where('staff_profile_id', $staffProfileId)
            ->whereIn('status', ServiceSessionStatus::values(ServiceSessionStatus::activeStatuses()))
            ->exists();

        if ($exists) {
            throw DuplicateActiveSessionException::forPersonnel();
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     *
     * @throws DuplicateActiveSessionException
     */
    public function mappingDuplicate(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw DuplicateActiveSessionException::forPersonnel();
            }

            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23505 = unique_violation. Match the SQLSTATE and/or our index names; never
        // surface either to the caller.
        if ($e->getCode() === '23505') {
            return true;
        }

        $message = $e->getMessage();

        return str_contains($message, 'service_sessions_active_staff_unique')
            || str_contains($message, 'service_sessions_queue_entry_id_unique');
    }
}
