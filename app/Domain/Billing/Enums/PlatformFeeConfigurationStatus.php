<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Percentage platform-fee configuration lifecycle (Plan §13.10, §51; Phase 20E).
 * Mirrors the PostgreSQL CHECK on `platform_fee_configurations.status`, factories,
 * request validation/OpenAPI/TS, and audit context. Parity guarded by
 * `Phase20EEnumParityTest`. Approved monetary terms are immutable — a change is a
 * supersede (new version), never an in-place edit.
 */
enum PlatformFeeConfigurationStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Superseded = 'superseded';
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

    /** Approved (non-draft, non-cancelled) states carry approval metadata. */
    public function requiresApproval(): bool
    {
        return match ($this) {
            self::Scheduled, self::Active, self::Superseded => true,
            self::Draft, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Superseded || $this === self::Cancelled;
    }

    /** Only `active` configurations participate in effective resolution. */
    public function isResolvable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Allowed lifecycle transitions (Plan §13.10; Phase 20E). See
     * docs/architecture/state-machines/platform-fee-configuration.md.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled, self::Active, self::Cancelled],
            self::Scheduled => [self::Active, self::Cancelled],
            self::Active => [self::Superseded],
            self::Superseded, self::Cancelled => [],
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
            self::Scheduled => 'Scheduled',
            self::Active => 'Active',
            self::Superseded => 'Superseded',
            self::Cancelled => 'Cancelled',
        };
    }
}
