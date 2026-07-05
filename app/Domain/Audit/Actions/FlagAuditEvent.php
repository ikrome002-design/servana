<?php

declare(strict_types=1);

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditFlaggedEventStatus;
use App\Domain\Audit\Exceptions\AuditFlaggedEventException;
use App\Domain\Audit\Models\AuditFlaggedEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Flag a branch-scoped audit event for review (Plan §13.2, §80; Phase 19). Creates a
 * new `open` review record over ONE immutable audit_logs row — it never mutates the
 * source row. Only branch-scoped audit rows (non-null merchant + branch) are flaggable.
 */
final class FlagAuditEvent
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(AuditLog $auditLog, User $actor, ?string $note = null): AuditFlaggedEvent
    {
        if ($auditLog->merchant_id === null || $auditLog->branch_id === null) {
            throw AuditFlaggedEventException::notBranchScoped();
        }

        $note = $note !== null ? trim($note) : null;

        return DB::transaction(function () use ($auditLog, $actor, $note): AuditFlaggedEvent {
            $flag = new AuditFlaggedEvent;
            $flag->forceFill([
                'merchant_id' => $auditLog->merchant_id,
                'branch_id' => $auditLog->branch_id,
                'audit_log_id' => $auditLog->id,
                'status' => AuditFlaggedEventStatus::Open->value,
                'review_notes' => $note !== '' ? $note : null,
                'created_by' => $actor->id,
            ])->save();

            $this->audit->record(AuditEvent::AuditEventFlagged, $actor, $flag->merchant_id, $flag->branch_id, $flag, [
                'flagged_event_id' => $flag->ulid,
                'audit_action' => $auditLog->action,
            ]);

            return $flag;
        });
    }
}
