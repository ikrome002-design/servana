<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Enums;

/**
 * Finance export lifecycle (Plan §65, §67; Phase 18B). Mirrors the
 * finance_exports.status DB CHECK. Status is never assigned directly; every change
 * goes through the request action / GenerateFinanceExport job / expiry / revoke. See
 * docs/architecture/state-machines/finance-export.md.
 */
enum FinanceExportStatus: string
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
}
