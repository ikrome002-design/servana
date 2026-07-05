<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Enums\AuditExportStatus;
use App\Domain\Audit\Exceptions\AuditExportException;

/**
 * Audit export transition guard (Plan §13.5, §80; Phase 19; ADR-010). Every status
 * change goes through the request action / GenerateAuditExport job / expiry / revoke
 * calling {@see ensure()}; an unlisted transition is rejected with `422
 * invalid_state_transition`. Mirrors the `audit_exports.status` DB CHECK.
 */
final class AuditExportStateMachine
{
    public function ensure(AuditExportStatus $from, AuditExportStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw AuditExportException::invalidTransition($from, $to);
        }
    }
}
