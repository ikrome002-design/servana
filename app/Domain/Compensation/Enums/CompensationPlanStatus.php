<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Personnel compensation-plan lifecycle (Plan §59, §80; Scope §12.9 eight-status
 * vocabulary; Phase 20F). Mirrors the PostgreSQL CHECK on
 * `personnel_compensation_plans.status`, factories, and audit context; parity guarded by
 * `Phase20FEnumParityTest`. Lifecycle spec:
 * docs/architecture/state-machines/personnel-compensation-plan.md.
 *
 * `draft` is the ONLY state whose terms may be edited in place (F7). Once non-draft, the
 * monetary/effective/subject terms are immutable at the database (BEFORE UPDATE trigger)
 * — a change is a SUPERSEDE (new version), never an in-place edit.
 */
enum CompensationPlanStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Expired = 'expired';
    case Superseded = 'superseded';
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
            self::Expired, self::Superseded, self::Rejected, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Statuses that hold an effective window and therefore participate in the DB
     * overlap exclusion (F3). Draft/pending/terminal rows NEVER block a window.
     *
     * @return list<string>
     */
    public static function overlapGuardedValues(): array
    {
        return [self::Active->value, self::Scheduled->value];
    }

    /** Only an `active` plan resolves as the effective configuration. */
    public function isResolvable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Allowed lifecycle transitions (authoritative arrow set — see the state-machine
     * spec). Supersede is a CONSEQUENCE of approving a successor, not a user action.
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
            self::Expired, self::Superseded, self::Rejected, self::Cancelled => [],
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
            self::Expired => 'Expired',
            self::Superseded => 'Superseded',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }
}
