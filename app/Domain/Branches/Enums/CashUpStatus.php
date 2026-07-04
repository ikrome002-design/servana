<?php

declare(strict_types=1);

namespace App\Domain\Branches\Enums;

use App\Domain\Branches\Services\CashUpStateMachine;

/**
 * Cash-up state (Plan §45; Phase 18B; extends the Phase 7 seam). Mirrors the DB CHECK.
 * Status is never assigned directly; every change goes through a named action via
 * {@see CashUpStateMachine}. See
 * docs/architecture/state-machines/cash-up.md.
 */
enum CashUpStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case CorrectionRequested = 'correction_requested';
    case Locked = 'locked';

    /**
     * Authoritative transition inventory (Plan §45). Maker = Branch Manager;
     * checker = Finance.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Approved, self::Rejected, self::CorrectionRequested],
            self::CorrectionRequested => [self::Submitted],
            self::Approved => [self::Locked],
            self::Rejected, self::Locked => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * @param  list<self>  $statuses
     * @return list<string>
     */
    public static function values(array $statuses): array
    {
        return array_map(static fn (self $s): string => $s->value, $statuses);
    }
}
