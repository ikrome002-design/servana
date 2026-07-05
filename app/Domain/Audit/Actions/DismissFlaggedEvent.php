<?php

declare(strict_types=1);

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditFlaggedEventStatus;
use App\Domain\Audit\Exceptions\AuditFlaggedEventException;
use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Audit\Services\AuditFlaggedEventStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Dismiss a flagged event under review as benign / not actionable (Plan §25; Phase 19).
 * `under_review` → `dismissed` with a mandatory review note + resolver. Review metadata
 * only; the source row is untouched and never deleted.
 */
final class DismissFlaggedEvent
{
    public function __construct(
        private readonly AuditFlaggedEventStateMachine $machine,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(AuditFlaggedEvent $flag, User $actor, string $note): AuditFlaggedEvent
    {
        $note = trim($note);
        if ($note === '') {
            throw AuditFlaggedEventException::reviewNoteRequired();
        }

        return DB::transaction(function () use ($flag, $actor, $note): AuditFlaggedEvent {
            /** @var AuditFlaggedEvent $locked */
            $locked = AuditFlaggedEvent::query()->whereKey($flag->id)->lockForUpdate()->firstOrFail();

            $this->machine->ensure($locked->status, AuditFlaggedEventStatus::Dismissed);
            $locked->forceFill([
                'status' => AuditFlaggedEventStatus::Dismissed->value,
                'resolved_by' => $actor->id,
                'review_notes' => $note,
            ])->save();

            $this->audit->record(AuditEvent::AuditFlaggedDismissed, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
                'flagged_event_id' => $locked->ulid,
            ]);

            return $locked;
        });
    }
}
