<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Scheduling\Concerns\BuildsQueueAudit;
use App\Domain\Scheduling\Concerns\BuildsServiceSessionAudit;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Exceptions\QueueEntryStateException;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Scheduling\Services\DuplicateActiveSessionGuard;
use App\Domain\Scheduling\Services\PreferredPersonnelExecutionValidator;
use App\Domain\Scheduling\Services\QueueEntryStateMachine;
use App\Domain\Scheduling\Services\QueuePersonnelAssignmentValidator;
use App\Domain\Scheduling\Services\QueuePositionService;
use App\Domain\Scheduling\Services\QueueWaitEstimator;
use App\Domain\Scheduling\Services\ServiceSessionStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Start service for a called queue entry (Plan §37, §25.2; Phase 16B + 16C; called →
 * in_service). The Phase 16C orchestration point: in ONE transaction it revalidates
 * the assigned personnel, enforces preferred-personnel execution + duplicate-active
 * protection, transitions the queue entry to `in_service`, and creates+starts EXACTLY
 * one linked {@see ServiceSession} (`pending → in_progress`). Any failure rolls back
 * the queue change AND the session with no success audit event. No invoice, payment,
 * receipt, or commission ledger is created.
 */
final class StartQueueEntry
{
    use BuildsQueueAudit;
    use BuildsServiceSessionAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly QueueEntryStateMachine $machine,
        private readonly QueuePositionService $position,
        private readonly QueuePersonnelAssignmentValidator $validator,
        private readonly QueueWaitEstimator $estimator,
        private readonly ServiceSessionStateMachine $sessionMachine,
        private readonly DuplicateActiveSessionGuard $duplicateGuard,
        private readonly PreferredPersonnelExecutionValidator $preferred,
    ) {}

    public function handle(QueueEntry $entry, User $actor): QueueEntry
    {
        return DB::transaction(function () use ($entry, $actor): QueueEntry {
            $this->position->lock($entry->merchant_id, $entry->branch_id);

            /** @var QueueEntry $locked */
            $locked = QueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, QueueEntryStatus::InService);

            // A service session requires the personnel actually performing the work.
            /** @var StaffProfile|null $staff */
            $staff = $locked->assignedPersonnel()->first();
            if ($staff === null) {
                throw QueueEntryStateException::invalidTransition($locked->status, QueueEntryStatus::InService);
            }

            // Reuse the queue scheduling gate (merchant/branch/lifecycle/active-assignment/
            // service-status/eligibility/availability + queue conflict) — NO duplication.
            $this->validator->ensure($locked, $staff);

            // Preferred-personnel EXECUTION evidence (honoured/overridden); never bypasses
            // eligibility (validated above). Reason for an override was recorded at assign time.
            $honored = $this->preferred->resolveHonored($locked, $staff);

            // Friendly duplicate-active pre-check under the lock; PostgreSQL remains the authority.
            $this->duplicateGuard->ensureNoActiveSession($locked->merchant_id, (int) $staff->id);

            // Queue: called → in_service.
            $locked->status = QueueEntryStatus::InService;
            $locked->started_at = now();
            $locked->save();

            // Service session: create (pending) → in_progress, in the same transaction.
            // Authoritative values are derived from the LOCKED source (never the request).
            $session = $this->duplicateGuard->mappingDuplicate(function () use ($locked, $staff, $honored, $actor): ServiceSession {
                $session = new ServiceSession([
                    'merchant_id' => $locked->merchant_id,
                    'branch_id' => $locked->branch_id,
                    'queue_entry_id' => $locked->id,
                    'client_id' => $locked->client_id,
                    'service_id' => $locked->service_id,
                    'staff_profile_id' => $staff->id,
                    'status' => ServiceSessionStatus::Pending,
                    'preferred_personnel_honored' => $honored,
                    'created_by' => $actor->id,
                ]);
                $session->save();

                $this->sessionMachine->ensure(ServiceSessionStatus::Pending, ServiceSessionStatus::InProgress);
                $session->status = ServiceSessionStatus::InProgress;
                $session->started_at = now();
                $session->save();

                return $session;
            });

            $this->estimator->recalculateBranch($locked->branch_id);
            $locked->refresh()->load(['client', 'service', 'assignedPersonnel', 'preferredPersonnel']);
            $session->refresh()->load(['client', 'service', 'personnel', 'queueEntry']);

            $this->audit->record(
                AuditEvent::QueueEntryStarted,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->queueAuditContext($locked, [
                    'previous_state' => QueueEntryStatus::Called->value,
                    'new_state' => QueueEntryStatus::InService->value,
                ]),
            );

            $this->audit->record(
                AuditEvent::ServiceSessionStarted,
                $actor,
                $session->merchant_id,
                $session->branch_id,
                $session,
                $this->serviceSessionAuditContext($session, [
                    'previous_state' => ServiceSessionStatus::Pending->value,
                    'new_state' => ServiceSessionStatus::InProgress->value,
                ]),
            );

            // Expose the created session to the caller (resource/frontend) without a re-query.
            $locked->setRelation('serviceSession', $session);

            return $locked;
        });
    }
}
