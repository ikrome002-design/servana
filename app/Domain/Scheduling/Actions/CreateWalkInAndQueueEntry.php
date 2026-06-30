<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Clients\Actions\CreateClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Concerns\BuildsQueueAudit;
use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\WalkIn;
use App\Domain\Scheduling\Services\QueueAssignmentResolver;
use App\Domain\Scheduling\Services\QueueCapacityGuard;
use App\Domain\Scheduling\Services\QueuePositionService;
use App\Domain\Scheduling\Services\QueueWaitEstimator;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Atomically create a walk-in client and its single queue entry (Plan §37;
 * Phase 16B). All of — branch-scoped client (attached or created via the existing
 * Phase 15A {@see CreateClient}, no duplicated logic), the walk-in row, the queue
 * entry, the assignment mode + optional preferred request, the initial position,
 * the estimate snapshot, and the audit events — commit together or roll back
 * together. The merchant/branch/status/position/estimate/actor/timestamps are
 * derived server-side. No service session, invoice, or preferred-personnel fee is
 * created.
 */
final class CreateWalkInAndQueueEntry
{
    use BuildsQueueAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly CreateClient $createClient,
        private readonly QueuePositionService $position,
        private readonly QueueCapacityGuard $capacity,
        private readonly QueueAssignmentResolver $resolver,
        private readonly QueueWaitEstimator $estimator,
    ) {}

    /**
     * @param  array<string, mixed>|null  $newClientData  complete Phase 15A client fields when no existing client
     */
    public function handle(
        MerchantBranch $branch,
        User $actor,
        ?Client $existingClient,
        ?array $newClientData,
        Service $service,
        QueueAssignmentMode $mode,
        ?StaffProfile $target = null,
        ?StaffProfile $preferred = null,
        ?int $overrideMinutes = null,
        ?string $overrideReason = null,
    ): QueueEntry {
        return DB::transaction(function () use (
            $branch, $actor, $existingClient, $newClientData, $service,
            $mode, $target, $preferred, $overrideMinutes, $overrideReason,
        ): QueueEntry {
            // One per-branch serialization point for the whole create.
            $this->position->lock($branch->merchant_id, $branch->id);
            $this->capacity->ensureOpenForNewEntry($branch);

            $client = $existingClient ?? $this->createClient->handle($branch, $actor, $newClientData ?? []);

            $walkIn = WalkIn::query()->create([
                'merchant_id' => $branch->merchant_id,
                'branch_id' => $branch->id,
                'client_id' => $client->id,
                'service_id' => $service->id,
                'assignment_mode' => $mode,
                'preferred_personnel_staff_profile_id' => $preferred?->id,
                'created_by' => $actor->id,
            ]);

            $entry = QueueEntry::query()->create([
                'merchant_id' => $branch->merchant_id,
                'branch_id' => $branch->id,
                'walk_in_id' => $walkIn->id,
                'client_id' => $client->id,
                'service_id' => $service->id,
                'preferred_personnel_staff_profile_id' => $preferred?->id,
                'assignment_mode' => $mode,
                'status' => QueueEntryStatus::Waiting,
                'position' => $this->position->nextPosition($branch->id),
                'queued_at' => now(),
                'estimated_wait_minutes' => 0,
                'estimated_wait_override_minutes' => $overrideMinutes,
                'estimated_wait_override_reason' => $overrideReason,
                'estimated_wait_overridden_by' => $overrideMinutes !== null ? $actor->id : null,
                'created_by' => $actor->id,
            ]);

            $assigned = $this->resolver->resolve($entry, $mode, $target, $preferred);
            if ($assigned !== null) {
                $entry->staff_profile_id = $assigned->id;
                $entry->status = QueueEntryStatus::Assigned;
                $entry->assigned_at = now();
                $entry->save();
            }

            $entry->estimated_wait_minutes = $this->estimator->estimateFor($entry);
            $entry->save();
            $this->estimator->recalculateBranch($branch->id);

            $this->audit->record(
                AuditEvent::WalkInCreated,
                $actor,
                $branch->merchant_id,
                $branch->id,
                $walkIn,
                [
                    'walk_in_id' => $walkIn->ulid,
                    'client_id' => $client->ulid,
                    'service_id' => $service->ulid,
                    'assignment_mode' => $mode->value,
                    'preferred_personnel_id' => $preferred?->ulid,
                ],
            );

            $this->audit->record(
                AuditEvent::QueueEntryCreated,
                $actor,
                $branch->merchant_id,
                $branch->id,
                $entry,
                $this->queueAuditContext($entry, [
                    'source' => 'walk_in',
                    'walk_in_id' => $walkIn->ulid,
                    'new_state' => $entry->status->value,
                    'new_position' => $entry->position,
                ]),
            );

            if ($overrideMinutes !== null) {
                $this->audit->record(
                    AuditEvent::QueueEntryWaitEstimateOverridden,
                    $actor,
                    $branch->merchant_id,
                    $branch->id,
                    $entry,
                    $this->queueAuditContext($entry, [
                        'calculated_wait_minutes' => $entry->estimated_wait_minutes,
                        'override_wait_minutes' => $overrideMinutes,
                        'reason' => $overrideReason,
                    ]),
                );
            }

            return $entry->load(['client', 'service', 'assignedPersonnel', 'preferredPersonnel', 'walkIn']);
        });
    }
}
