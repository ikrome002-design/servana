<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

use App\Domain\Audit\Jobs\GenerateAuditExport;
use App\Domain\Audit\Services\AuditExportStateMachine;

/**
 * Audit export lifecycle (Plan §13.5, §19.2/§19.3, §80; Phase 19; ADR-010). Mirrors
 * the `audit_exports.status` DB CHECK. Status is never assigned directly — every
 * change goes through the request action / {@see GenerateAuditExport}
 * job / expiry / revoke via {@see AuditExportStateMachine}.
 * Naming mirrors the Finance-export lifecycle (`FinanceExportStatus`).
 */
enum AuditExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Processing],
            self::Processing => [self::Ready, self::Failed],
            self::Ready => [self::Expired, self::Revoked],
            self::Failed => [self::Queued],
            self::Expired, self::Revoked => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
