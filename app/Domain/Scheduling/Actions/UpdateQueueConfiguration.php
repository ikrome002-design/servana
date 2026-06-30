<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\BranchDayRecord;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Exceptions\QueueConflictException;
use App\Domain\Scheduling\Services\QueueCapacityGuard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a branch's queue operational configuration (Plan §37; Phase 16B). Branch
 * Manager only (authorized upstream). Targets today's Branch Day record:
 * `queue_is_open`, `queue_capacity` (> 0 or null; never below the current active
 * count → 422 capacity_below_active), and `queue_default_assignment_mode`
 * (next_available|manual). It never assigns/transfers/reorders or mutates an
 * individual entry. Closing the queue blocks new entries but never deletes/cancels
 * existing ones. Every change is audited.
 */
final class UpdateQueueConfiguration
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly QueueCapacityGuard $capacity,
    ) {}

    public function handle(
        MerchantBranch $branch,
        User $actor,
        ?bool $queueIsOpen,
        bool $capacityProvided,
        ?int $capacityValue,
        ?QueueAssignmentMode $defaultMode,
    ): BranchDayRecord {
        return DB::transaction(function () use ($branch, $actor, $queueIsOpen, $capacityProvided, $capacityValue, $defaultMode): BranchDayRecord {
            $day = $this->capacity->todayBranchDay($branch);

            if ($day === null) {
                throw QueueConflictException::branchDayNotOpen();
            }

            /** @var BranchDayRecord $day */
            $day = BranchDayRecord::query()->whereKey($day->id)->lockForUpdate()->firstOrFail();

            if ($capacityProvided && $capacityValue !== null && $capacityValue < $this->capacity->activeCount($branch)) {
                throw QueueConflictException::capacityBelowActive();
            }

            $changes = [];
            if ($queueIsOpen !== null) {
                $changes['queue_is_open'] = ['from' => $day->queue_is_open, 'to' => $queueIsOpen];
                $day->queue_is_open = $queueIsOpen;
            }
            if ($capacityProvided) {
                $changes['queue_capacity'] = ['from' => $day->queue_capacity, 'to' => $capacityValue];
                $day->queue_capacity = $capacityValue;
            }
            if ($defaultMode !== null) {
                $changes['queue_default_assignment_mode'] = ['from' => $day->queue_default_assignment_mode->value, 'to' => $defaultMode->value];
                $day->queue_default_assignment_mode = $defaultMode;
            }

            $day->save();

            $this->audit->record(
                AuditEvent::QueueConfigurationUpdated,
                $actor,
                $branch->merchant_id,
                $branch->id,
                $day,
                [
                    'branch_day_id' => $day->ulid,
                    'business_date' => $day->business_date->toDateString(),
                    'changes' => $changes,
                ],
            );

            return $day;
        });
    }
}
