<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Exceptions\QueueConflictException;
use App\Domain\Scheduling\Models\QueueEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE single owner of queue-position integrity (Plan §37; Phase 16B).
 *
 * Every position-changing operation acquires ONE transaction-level PostgreSQL
 * advisory lock derived from (merchant_id, branch_id) — the single, consistent
 * serialization mechanism — so concurrent creates/reorders for a branch cannot
 * duplicate a position. The DB partial-unique index
 * `(branch_id, position) WHERE status IN (waiting,assigned,called)` is the final
 * authority; this service keeps the active-ordered set unique, positive, and the
 * waiting suffix contiguous.
 *
 * Position spans the ordered-active set (waiting/assigned/called). New entries
 * append at max+1; releasing an entry (cancel/no_show/complete) compacts the set to
 * 1..N; reorder permutes the WAITING entries across the slots they already occupy.
 *
 * MUST be called inside an open DB transaction (the advisory lock is xact-scoped).
 */
final class QueuePositionService
{
    private const TEMP_OFFSET = 2_000_000_000;

    /** Acquire the per-branch advisory queue lock for the current transaction. */
    public function lock(int $merchantId, int $branchId): void
    {
        DB::statement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ["queue:{$merchantId}:{$branchId}"],
        );
    }

    /** The next append position for a branch (max ordered-active position + 1). */
    public function nextPosition(int $branchId): int
    {
        $max = (int) QueueEntry::query()
            ->where('branch_id', $branchId)
            ->orderedActive()
            ->max('position');

        return $max + 1;
    }

    /**
     * Renumber the ordered-active set to a contiguous 1..N by current position
     * (ascending; ties by id). new <= old for every row, so an ascending update is
     * collision-free against the partial-unique index. Call after a release.
     */
    public function compact(int $branchId): void
    {
        $ids = QueueEntry::query()
            ->where('branch_id', $branchId)
            ->orderedActive()
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $position = 1;
        foreach ($ids as $id) {
            QueueEntry::query()->whereKey($id)->update(['position' => $position]);
            $position++;
        }
    }

    /**
     * Permute the WAITING entries across the position slots they currently occupy,
     * in the submitted order. Two-phase (temp offset → final) avoids transient
     * partial-unique collisions. The submitted set must be the COMPLETE set of
     * branch waiting ULIDs (validated by the caller); a stale snapshot → 409.
     *
     * @param  list<string>  $orderedWaitingUlids
     *
     * @throws QueueConflictException
     */
    public function reorderWaiting(int $branchId, array $orderedWaitingUlids): void
    {
        /** @var Collection<int, QueueEntry> $waiting */
        $waiting = QueueEntry::query()
            ->where('branch_id', $branchId)
            ->where('status', QueueEntryStatus::Waiting->value)
            ->orderBy('position')
            ->get();

        $currentUlids = $waiting->pluck('ulid')->all();
        $submitted = $orderedWaitingUlids;

        // Reject duplicates in the submitted set.
        if (count($submitted) !== count(array_unique($submitted))) {
            throw QueueConflictException::orderChanged();
        }

        // The submitted set must be EXACTLY the current waiting set (no omission,
        // no foreign/terminal/other-branch entry, no stale snapshot).
        if (count($submitted) !== $waiting->count()
            || array_diff($submitted, $currentUlids) !== []
            || array_diff($currentUlids, $submitted) !== []) {
            throw QueueConflictException::orderChanged();
        }

        $slots = $waiting->pluck('position')->sort()->values()->all();
        /** @var array<string, int> $idByUlid */
        $idByUlid = $waiting->pluck('id', 'ulid')->all();

        // Phase A: move waiting entries to unique temp positions out of the way.
        $temp = self::TEMP_OFFSET;
        foreach ($submitted as $ulid) {
            QueueEntry::query()->whereKey($idByUlid[$ulid])->update(['position' => $temp]);
            $temp++;
        }

        // Phase B: assign the existing slot positions in the submitted order.
        foreach ($submitted as $index => $ulid) {
            QueueEntry::query()->whereKey($idByUlid[$ulid])->update(['position' => $slots[$index]]);
        }
    }
}
