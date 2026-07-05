<?php

declare(strict_types=1);

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditFlaggedEventStatus;
use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Audit\Services\AuditFlaggedEventStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reopen a resolved/dismissed flagged event for further review (Plan §25; Phase 19).
 * `resolved`/`dismissed` → `reopened`. The prior resolver + notes are cleared so the row
 * satisfies the resolution invariant (only a terminal outcome carries a resolver + notes);
 * the who/when history is preserved immutably in the audit_logs trail. The next review
 * assigns a fresh reviewer via StartFlaggedEventReview.
 */
final class ReopenFlaggedEvent
{
    public function __construct(
        private readonly AuditFlaggedEventStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(AuditFlaggedEvent $flag, User $actor): AuditFlaggedEvent
    {
        return DB::transaction(function () use ($flag, $actor): AuditFlaggedEvent {
            /** @var AuditFlaggedEvent $locked */
            $locked = AuditFlaggedEvent::query()->whereKey($flag->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, AuditFlaggedEventStatus::Reopened);
            $locked->forceFill([
                'status' => AuditFlaggedEventStatus::Reopened->value,
                'resolved_by' => null,
                'review_notes' => null,
                'assigned_to' => null,
            ])->save();

            $this->audit->record(AuditEvent::AuditFlaggedReopened, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
                'flagged_event_id' => $locked->ulid,
            ]);

            return $locked;
        });
    }
}
