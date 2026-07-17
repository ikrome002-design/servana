<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Commission-rule lifecycle (Plan §59, §80; Scope §12.7 Step 3A/3C, §18.3; Phase 20F).
 * Mirrors the PostgreSQL CHECK on `commission_rules.status`; parity guarded by
 * `Phase20FEnumParityTest`. Lifecycle spec:
 * docs/architecture/state-machines/commission-rule.md.
 *
 * A previously active rule is ENDED, not deleted (Scope §12.7 Step 3C): `active →
 * superseded` preserves the row and its terms byte-identical.
 */
enum CommissionRuleStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Superseded = 'superseded';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * All backing values, canonical order — authoritative for the DB CHECK and parity.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** The only state whose terms may be edited in place (F7). */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Superseded, self::Expired, self::Rejected, self::Cancelled => true,
            default => false,
        };
    }

    /** Only an `active` rule resolves as the effective configuration. */
    public function isResolvable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Allowed lifecycle transitions (authoritative arrow set — see the state-machine
     * spec). `end` (active → superseded) is a consequence of a successor activating.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Cancelled],
            self::PendingApproval => [self::Active, self::Scheduled, self::Rejected],
            self::Scheduled => [self::Active, self::Cancelled],
            self::Active => [self::Superseded, self::Expired],
            self::Superseded, self::Expired, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Scheduled => 'Scheduled',
            self::Active => 'Active',
            self::Superseded => 'Superseded',
            self::Expired => 'Expired',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }
}
