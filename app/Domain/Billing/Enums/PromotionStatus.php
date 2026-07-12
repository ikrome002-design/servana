<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Promotional-discount lifecycle status (Plan §53; Phase 20C). See
 * docs/architecture/state-machines/promotional-discount.md. Only `active` records are
 * eligible for resolution. Transitions run through `PromotionalDiscountStateMachine`;
 * an unlisted pair returns 422 invalid_state_transition. `expired`/`cancelled` are
 * terminal. Mirrored across the PHP enum, the PostgreSQL CHECK on
 * `promotional_discounts.status`, factories, request validation/OpenAPI/TS, and audit
 * context. Parity guarded by `Phase20CEnumParityTest`.
 *
 * Distinct from {@see FreePeriodOfferStatus}: promotions allow a direct `draft → active`
 * transition (approval of an already-current window); free-period offers do not.
 */
enum PromotionStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * All backing values, in canonical order — authoritative for the DB CHECK and
     * every parity assertion.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** Terminal states (retained history; a change requires a new record). */
    public function isTerminal(): bool
    {
        return $this === self::Expired || $this === self::Cancelled;
    }

    /** Only `active` records participate in resolution. */
    public function isResolvable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Allowed lifecycle transitions (Plan §53; Phase 20C).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled, self::Active, self::Cancelled],
            self::Scheduled => [self::Active, self::Cancelled],
            self::Active => [self::Paused, self::Expired],
            self::Paused => [self::Active],
            self::Expired, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** States that require prior approval (approved_by / approved_at set). */
    public function requiresApproval(): bool
    {
        return match ($this) {
            self::Scheduled, self::Active, self::Paused, self::Expired => true,
            self::Draft, self::Cancelled => false,
        };
    }

    /** Sentence-case label for UI/screen options. */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }
}
