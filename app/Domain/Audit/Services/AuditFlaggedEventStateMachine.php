<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Enums\AuditFlaggedEventStatus;
use App\Domain\Audit\Exceptions\AuditFlaggedEventException;

/**
 * Flagged-event transition guard (Plan §25; Phase 19). Every status change goes through
 * a named action calling {@see ensure()}; an unlisted transition is rejected with
 * `422 invalid_state_transition`. Only review metadata moves — the source audit_logs row
 * is immutable. See docs/architecture/state-machines/audit-flagged-event.md.
 */
final class AuditFlaggedEventStateMachine
{
    public function ensure(AuditFlaggedEventStatus $from, AuditFlaggedEventStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw AuditFlaggedEventException::invalidTransition($from, $to);
        }
    }
}
