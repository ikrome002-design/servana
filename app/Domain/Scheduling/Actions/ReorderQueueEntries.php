<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Services\QueuePositionService;
use App\Domain\Scheduling\Services\QueueWaitEstimator;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reorder a branch's WAITING queue entries (Plan §37; Phase 16B). The request must
 * carry the COMPLETE ordered set of active waiting ULIDs; duplicates, omissions,
 * foreign/terminal/other-branch entries, and a stale snapshot are rejected with a
 * deterministic `409 queue_order_changed` (in {@see QueuePositionService}). Runs
 * under the per-branch advisory lock so concurrent reorders stay deterministic.
 */
final class ReorderQueueEntries
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly QueuePositionService $position,
        private readonly QueueWaitEstimator $estimator,
    ) {}

    /**
     * @param  list<string>  $orderedUlids
     * @return Collection<int, QueueEntry>
     */
    public function handle(MerchantBranch $branch, User $actor, array $orderedUlids): Collection
    {
        return DB::transaction(function () use ($branch, $actor, $orderedUlids): Collection {
            $this->position->lock($branch->merchant_id, $branch->id);
            $this->position->reorderWaiting($branch->id, $orderedUlids);
            $this->estimator->recalculateBranch($branch->id);

            /** @var Collection<int, QueueEntry> $waiting */
            $waiting = QueueEntry::query()
                ->where('branch_id', $branch->id)
                ->where('status', QueueEntryStatus::Waiting->value)
                ->orderBy('position')
                ->with(['client', 'service', 'assignedPersonnel', 'preferredPersonnel'])
                ->get();

            $this->audit->record(
                AuditEvent::QueueEntryReordered,
                $actor,
                $branch->merchant_id,
                $branch->id,
                null,
                [
                    'count' => $waiting->count(),
                    'order' => $waiting->map(static fn (QueueEntry $e): array => [
                        'queue_entry_id' => $e->ulid,
                        'position' => $e->position,
                    ])->all(),
                ],
            );

            return $waiting;
        });
    }
}
