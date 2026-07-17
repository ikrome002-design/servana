<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * Append-only compensation change-history event (Plan §59, §80; Scope §12 "compensation
 * change history"; Phase 20F). Mirrors the PostgreSQL CHECK on
 * `compensation_plan_history.event`; parity guarded by `Phase20FEnumParityTest`.
 *
 * History records CONFIGURATION changes — never money owed, accrued, earned, or paid.
 * A row is written in the SAME transaction as the plan transition that produced it.
 */
enum CompensationPlanHistoryEvent: string
{
    case Created = 'created';
    case UpdatedDraft = 'updated_draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    /** The `scheduled → active` boundary — the symmetric partner of `expired`. */
    case Activated = 'activated';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';
    case Expired = 'expired';

    /**
     * All backing values, canonical order — authoritative for the DB CHECK and parity.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $e): string => $e->value, self::cases());
    }

    /** `created` is the only event with no prior status. */
    public function hasFromStatus(): bool
    {
        return $this !== self::Created;
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::UpdatedDraft => 'Updated draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Activated => 'Activated',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Superseded => 'Superseded',
            self::Expired => 'Expired',
        };
    }
}
