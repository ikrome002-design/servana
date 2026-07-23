<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Enums;

/**
 * Billable-SMS entry lifecycle (Plan §13.13, §64; Phase 21S). Mirrors the
 * `sms_billing_entries.status` DB CHECK exactly.
 *
 * `provisional` is created inside the confirm transaction from the ESTIMATED quantity, so a
 * campaign can never be sent without an accompanying charge record. It becomes `billable` when the
 * campaign settles and the quantity is known, `cancelled` if nothing was ever dispatched.
 * `invoiced` is set by the future phase that rolls SMS charges into a subscription invoice line —
 * Phase 21S deliberately owns only the queue, never the invoicing.
 *
 * Servana moves NO money here (ADR-012): no Wallet payment resource, no payment attempt, no
 * provider call.
 */
enum SmsBillingEntryStatus: string
{
    case Provisional = 'provisional';
    case Billable = 'billable';
    case Invoiced = 'invoiced';
    case Credited = 'credited';
    case Cancelled = 'cancelled';

    /**
     * All backing values, in canonical order — the authoritative list for the DB CHECK and every
     * parity assertion.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Credited, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * The statuses covered by the partial unique index that permits at most ONE live entry per
     * campaign — the structural no-double-billing guarantee.
     *
     * @return list<string>
     */
    public static function liveValues(): array
    {
        return [self::Provisional->value, self::Billable->value, self::Invoiced->value];
    }

    /**
     * Authoritative Phase-21S transition inventory.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Provisional => [self::Billable, self::Cancelled],
            self::Billable => [self::Invoiced, self::Credited, self::Cancelled],
            // Only a correction may follow invoicing; the original charge is never rewritten.
            self::Invoiced => [self::Credited],
            self::Credited, self::Cancelled => [],
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
            self::Provisional => 'Provisional',
            self::Billable => 'Billable',
            self::Invoiced => 'Invoiced',
            self::Credited => 'Credited',
            self::Cancelled => 'Cancelled',
        };
    }
}
