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
 * Start reviewing a flagged event (Plan §25; Phase 19). `open`/`reopened` → `under_review`,
 * assigning the reviewing auditor. Review metadata only; the source row is untouched.
 */
final class StartFlaggedEventReview
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

            $this->machine->ensure($locked->status, AuditFlaggedEventStatus::UnderReview);
            $locked->forceFill([
                'status' => AuditFlaggedEventStatus::UnderReview->value,
                'assigned_to' => $actor->id,
            ])->save();

            $this->audit->record(AuditEvent::AuditFlaggedReviewStarted, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
                'flagged_event_id' => $locked->ulid,
            ]);

            return $locked;
        });
    }
}
