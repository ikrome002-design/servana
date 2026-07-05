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
 * Resolve a flagged event under review (Plan §25; Phase 19). `under_review` → `resolved`
 * with a mandatory review note + resolver. Review metadata only; the source row is
 * untouched.
 */
final class ResolveFlaggedEvent
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

            $this->machine->ensure($locked->status, AuditFlaggedEventStatus::Resolved);
            $locked->forceFill([
                'status' => AuditFlaggedEventStatus::Resolved->value,
                'resolved_by' => $actor->id,
                'review_notes' => $note,
            ])->save();

            $this->audit->record(AuditEvent::AuditFlaggedResolved, $actor, $locked->merchant_id, $locked->branch_id, $locked, [
                'flagged_event_id' => $locked->ulid,
            ]);

            return $locked;
        });
    }
}
