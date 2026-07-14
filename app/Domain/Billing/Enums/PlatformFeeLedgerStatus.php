<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Percentage platform-fee ledger-entry status (Plan §13.10, §51; Phase 20E).
 * Canonical Plan vocabulary — there is deliberately NO `provisional`, `billable`,
 * or `settled` state (`settled` = Wallet clearing of the subscription invoice =
 * Phase 20D-W, outside 20E). Mirrors the PostgreSQL CHECK on
 * `platform_fee_ledger_entries.status`. Parity guarded by `Phase20EEnumParityTest`.
 */
enum PlatformFeeLedgerStatus: string
{
    case Pending = 'pending';
    case Aggregated = 'aggregated';
    case Invoiced = 'invoiced';
    case Reversed = 'reversed';
    case Adjusted = 'adjusted';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /**
     * Allowed lifecycle transitions (Plan §13.10; Phase 20E). See
     * docs/architecture/state-machines/platform-fee-ledger-entry.md. `reversed`/`adjusted` are
     * terminal non-monetary markers over the original row (the money lives in additive entries).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Aggregated, self::Reversed, self::Adjusted],
            self::Aggregated => [self::Invoiced, self::Reversed, self::Adjusted],
            self::Invoiced => [self::Reversed, self::Adjusted],
            self::Reversed, self::Adjusted => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Reversed || $this === self::Adjusted;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Aggregated => 'Aggregated',
            self::Invoiced => 'Invoiced',
            self::Reversed => 'Reversed',
            self::Adjusted => 'Adjusted',
        };
    }
}
