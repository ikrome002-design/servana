<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Compensation\Services\CommissionPreviewService;
use App\Domain\Compensation\ValueObjects\CommissionPreviewResult;
use App\Domain\Scheduling\Concerns\BuildsQueueAudit;
use App\Domain\Scheduling\Concerns\BuildsServiceSessionAudit;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Exceptions\QueueEntryStateException;
use App\Domain\Scheduling\Models\QueueEntry;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Scheduling\Services\QueueEntryStateMachine;
use App\Domain\Scheduling\Services\QueuePositionService;
use App\Domain\Scheduling\Services\QueueWaitEstimator;
use App\Domain\Scheduling\Services\ServiceSessionStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Complete an in-service queue entry (Plan §37, §25.2; Phase 16B + 16C; in_service →
 * completed). The Phase 16C orchestration point: in ONE transaction it completes the
 * linked {@see ServiceSession} (`in_progress → completed`), completes the queue entry,
 * releases the active position, and produces a typed NON-PAYABLE commission
 * {@see CommissionPreviewResult} — never earned, validated, or payable, and never a
 * `commission_ledger` / invoice row (Phase 17 invoices later). Any failure rolls back
 * the queue change AND the session with no success audit event.
 */
final class CompleteQueueEntry
{
    use BuildsQueueAudit;
    use BuildsServiceSessionAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly QueueEntryStateMachine $machine,
        private readonly QueuePositionService $position,
        private readonly QueueWaitEstimator $estimator,
        private readonly ServiceSessionStateMachine $sessionMachine,
        private readonly CommissionPreviewService $commissionPreview,
    ) {}

    /**
     * @return array{entry: QueueEntry, session: ServiceSession|null, preview: CommissionPreviewResult|null}
     */
    public function handle(QueueEntry $entry, User $actor): array
    {
        return DB::transaction(function () use ($entry, $actor): array {
            $this->position->lock($entry->merchant_id, $entry->branch_id);

            /** @var QueueEntry $locked */
            $locked = QueueEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, QueueEntryStatus::Completed);

            // Complete the linked service session first (in_progress → completed).
            $session = ServiceSession::query()
                ->where('queue_entry_id', $locked->id)
                ->lockForUpdate()
                ->first();

            $preview = null;
            if ($session !== null) {
                if ($session->status !== ServiceSessionStatus::InProgress) {
                    // The queue entry is in_service but its session is not in_progress —
                    // an inconsistent aggregate; refuse rather than silently complete.
                    throw QueueEntryStateException::invalidTransition($locked->status, QueueEntryStatus::Completed);
                }

                $this->sessionMachine->ensure(ServiceSessionStatus::InProgress, ServiceSessionStatus::Completed);
                $session->status = ServiceSessionStatus::Completed;
                $session->completed_at = now();
                $session->save();

                // NON-PAYABLE preview only — no ledger, no invoice, never earned/payable.
                $preview = $this->commissionPreview->previewForCompletion($session);
            }

            $locked->status = QueueEntryStatus::Completed;
            $locked->completed_at = now();
            $locked->save();

            // Released from the active-ordered set → compact the remaining queue.
            $this->position->compact($locked->branch_id);
            $this->estimator->recalculateBranch($locked->branch_id);
            $locked->refresh()->load(['client', 'service', 'assignedPersonnel', 'preferredPersonnel']);

            $this->audit->record(
                AuditEvent::QueueEntryCompleted,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->queueAuditContext($locked, [
                    'previous_state' => QueueEntryStatus::InService->value,
                    'new_state' => QueueEntryStatus::Completed->value,
                ]),
            );

            if ($session !== null) {
                $session->refresh()->load(['client', 'service', 'personnel', 'queueEntry']);
                $this->audit->record(
                    AuditEvent::ServiceSessionCompleted,
                    $actor,
                    $session->merchant_id,
                    $session->branch_id,
                    $session,
                    $this->serviceSessionAuditContext($session, [
                        'previous_state' => ServiceSessionStatus::InProgress->value,
                        'new_state' => ServiceSessionStatus::Completed->value,
                        'commission_preview' => $preview->toArray(),
                    ]),
                );
                $locked->setRelation('serviceSession', $session);
            }

            return ['entry' => $locked, 'session' => $session, 'preview' => $preview];
        });
    }
}
