<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Lifecycle status of an earnings_queries row (Plan §63; §25.4; Phase 20H). Mirrors the
 * earnings_queries.status DB CHECK; parity guarded by Phase20HEnumParityTest.
 *
 * A personnel query is `open` on creation; a triage owner may pick it up (`assigned`); Finance
 * responds to `resolved` or `rejected` (terminal). Resolution NEVER mutates a ledger silently — a
 * monetary correction is a separate `compensation_adjustments` row referenced by
 * `resolved_adjustment_id`.
 */
enum EarningsQueryStatus: string
{
    case Open = 'open';
    case Assigned = 'assigned';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return $this === self::Resolved || $this === self::Rejected;
    }

    /**
     * Authoritative status transitions (docs/architecture/state-machines/earnings-query.md).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Assigned, self::Resolved, self::Rejected],
            self::Assigned => [self::Resolved, self::Rejected],
            self::Resolved, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
