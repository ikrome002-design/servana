<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Enums;

use App\Domain\Clients\Enums\ConsentState;

/**
 * The consent state captured on a recipient snapshot at confirm time (Plan §13.13, §64; Phase 21S).
 * Mirrors the `personnel_sms_recipients.consent_status_snapshot` DB CHECK exactly.
 *
 * This is deliberately NOT {@see ConsentState}: the 15A enum has only `opted_in` / `opted_out`
 * because a `client_consents` row always records a decision, while the SMS snapshot must also be
 * able to say **no decision was ever recorded** — the absence of a row. Missing consent is treated
 * exactly like an opt-out for delivery purposes (fail closed): both suppress the recipient.
 */
enum SmsConsentSnapshotStatus: string
{
    case OptedIn = 'opted_in';
    case OptedOut = 'opted_out';
    /** No `client_consents` row exists for the (client, sms) pair — never assume consent. */
    case Missing = 'missing';

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

    /** Map a 15A consent state (or its absence) onto the snapshot vocabulary. */
    public static function fromConsentState(?ConsentState $state): self
    {
        return match ($state) {
            ConsentState::OptedIn => self::OptedIn,
            ConsentState::OptedOut => self::OptedOut,
            null => self::Missing,
        };
    }

    /** Only an explicit opt-in permits delivery. */
    public function permitsDelivery(): bool
    {
        return $this === self::OptedIn;
    }

    /** The safe exclusion reason a non-delivering snapshot maps to. */
    public function exclusionReason(): ?SmsRecipientExclusionReason
    {
        return match ($this) {
            self::OptedIn => null,
            self::OptedOut => SmsRecipientExclusionReason::ConsentOptedOut,
            self::Missing => SmsRecipientExclusionReason::ConsentMissing,
        };
    }
}
