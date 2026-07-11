<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Preferred-personnel fee rule lifecycle (Plan §13.10, §47; Phase 20A). Mirrors the DB
 * CHECK on `preferred_personnel_fee_rules.status`. Status is never assigned directly;
 * changes run through named domain actions via the rule state machine
 * (docs/architecture/state-machines/preferred-personnel-fee-rule.md). Only `active` and
 * `scheduled` rules participate in the overlap-exclusion constraint. `active` monetary
 * terms are immutable — a change supersedes with a new version.
 */
enum PreferredPersonnelFeeRuleStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Superseded = 'superseded';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** States that participate in the DB overlap-exclusion (active + scheduled). */
    public function reservesRange(): bool
    {
        return $this === self::Active || $this === self::Scheduled;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Superseded, self::Expired, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Authoritative transition inventory (Plan §13.10 lifecycle).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled, self::Active, self::Cancelled],
            self::Scheduled => [self::Active, self::Cancelled],
            self::Active => [self::Superseded, self::Expired],
            self::Superseded, self::Expired, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
