<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Scheduling\Concerns\BuildsServiceSessionAudit;
use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Domain\Scheduling\Exceptions\ServiceSessionConflictException;
use App\Domain\Scheduling\Exceptions\ServiceSessionStateException;
use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Scheduling\Services\ServiceSessionStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a service session (Plan §25.2; Phase 16C). The four-state machine defines
 * `pending → cancelled` and `in_progress → cancelled`, but per Gate C this action
 * exposes cancellation only where it does not strand a queue entry: a queue-linked
 * session that is already `in_progress` (its queue entry at `in_service`) is refused
 * with `409 service_session_in_progress` because the Queue Entry machine defines no
 * `in_service → cancelled` transition — completion is the only exit until a future
 * queue-machine extension lands. A non-queue-linked in-progress session (no such path
 * in 16C) and any `pending` session may be cancelled. A reason is required.
 */
final class CancelServiceSession
{
    use BuildsServiceSessionAudit;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly ServiceSessionStateMachine $machine,
    ) {}

    public function handle(ServiceSession $session, User $actor, string $reason): ServiceSession
    {
        $clean = trim($reason);
        if ($clean === '') {
            throw ServiceSessionStateException::reasonRequired();
        }

        return DB::transaction(function () use ($session, $actor, $clean): ServiceSession {
            /** @var ServiceSession $locked */
            $locked = ServiceSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            // Gate C: a queue-linked in-progress session cannot be aborted in 16C
            // (no `in_service → cancelled` on the Queue Entry machine). Complete it instead.
            if ($locked->status === ServiceSessionStatus::InProgress && $locked->queue_entry_id !== null) {
                throw ServiceSessionConflictException::inProgressNotCancellable();
            }

            $previous = $locked->status;
            $this->machine->ensure($previous, ServiceSessionStatus::Cancelled);

            $locked->status = ServiceSessionStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $clean;
            $locked->save();

            $locked->refresh()->load(['client', 'service', 'personnel', 'queueEntry']);

            $this->audit->record(
                AuditEvent::ServiceSessionCancelled,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                $this->serviceSessionAuditContext($locked, [
                    'previous_state' => $previous->value,
                    'new_state' => ServiceSessionStatus::Cancelled->value,
                    'reason' => $clean,
                ]),
            );

            return $locked;
        });
    }
}
