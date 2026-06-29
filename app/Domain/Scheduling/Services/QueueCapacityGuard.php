<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Scheduling\Exceptions\QueueConflictException;
use App\Domain\Scheduling\Models\QueueEntry;
use Carbon\CarbonImmutable;

/**
 * Queue opening + capacity enforcement (Plan §37; Phase 16B).
 *
 * Anchored on the Branch Day aggregate (no `queue_configurations` table). A new
 * queue entry (walk-in or appointment conversion) requires: an active branch, an
 * OPEN Branch Day for today's `Africa/Nairobi` business date, an effectively open
 * queue, and capacity not reached. Failures raise stable, safe conflicts. Capacity
 * counting MUST run under the per-branch advisory lock (the caller holds it), so
 * concurrent creates cannot overfill the queue.
 */
final class QueueCapacityGuard
{
    /** Resolve today's Branch Day record for a branch (or null). */
    public function todayBranchDay(MerchantBranch $branch): ?BranchDayRecord
    {
        $businessDate = CarbonImmutable::now('Africa/Nairobi')->toDateString();

        return BranchDayRecord::query()
            ->where('branch_id', $branch->id)
            ->where('business_date', $businessDate)
            ->first();
    }

    /**
     * Assert a new entry may be created right now, returning today's Branch Day.
     *
     * @throws QueueConflictException
     */
    public function ensureOpenForNewEntry(MerchantBranch $branch): BranchDayRecord
    {
        if (! $branch->isActive()) {
            throw QueueConflictException::branchDayNotOpen();
        }

        $day = $this->todayBranchDay($branch);

        if ($day === null || $day->status->value !== 'open') {
            throw QueueConflictException::branchDayNotOpen();
        }

        if (! $day->effectiveQueueOpen()) {
            throw QueueConflictException::queueClosed();
        }

        $this->ensureCapacityNotReached($branch, $day);

        return $day;
    }

    /**
     * Reject when the branch's active queue has reached the configured capacity.
     * Null capacity = no explicit cap. Must be called under the advisory lock.
     *
     * @throws QueueConflictException
     */
    public function ensureCapacityNotReached(MerchantBranch $branch, BranchDayRecord $day): void
    {
        if ($day->queue_capacity === null) {
            return;
        }

        if ($this->activeCount($branch) >= $day->queue_capacity) {
            throw QueueConflictException::capacityReached();
        }
    }

    /** Count active (queue-occupying) entries for a branch. */
    public function activeCount(MerchantBranch $branch): int
    {
        return QueueEntry::query()
            ->where('branch_id', $branch->id)
            ->active()
            ->count();
    }
}
