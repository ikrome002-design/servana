<?php

declare(strict_types=1);

namespace App\Domain\FinanceOps\Services;

use App\Domain\FinanceOps\Enums\FinanceExportStatus;
use App\Domain\FinanceOps\Exceptions\FinanceExportException;

/**
 * Finance export transition guard (Plan §65, §67; Phase 18B). Every status change goes
 * through the request action / GenerateFinanceExport job / expiry / revoke calling
 * {@see ensure()}; an unlisted transition is rejected with `422
 * invalid_state_transition`. Mirrors the DB CHECK. See
 * docs/architecture/state-machines/finance-export.md.
 */
final class FinanceExportStateMachine
{
    public function ensure(FinanceExportStatus $from, FinanceExportStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw FinanceExportException::invalidTransition($from, $to);
        }
    }
}
