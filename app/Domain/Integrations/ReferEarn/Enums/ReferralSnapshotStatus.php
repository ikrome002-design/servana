<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Enums;

/**
 * Referral snapshot lifecycle (Plan §25.6, §58A.1; Phase 21R-A).
 *
 * Mirrors the `referral_snapshots.snapshot_status` DB CHECK and the trigger-enforced
 * non-regression rule:
 *
 *   captured ─► validating ─► validated ─► confirmed             (terminal)
 *      │            │             └─► expired_unconfirmed        (terminal)
 *      │            └─► rejected                                 (terminal)
 *      └─► invalid_format                                        (terminal; never sent to R&E)
 *
 * Retries stay within the same state (a `validating` snapshot that fails transiently stays
 * `validating`), so there is no self-transition and no regression anywhere in the machine.
 */
enum ReferralSnapshotStatus: string
{
    case Captured = 'captured';
    case InvalidFormat = 'invalid_format';
    case Validating = 'validating';
    case Validated = 'validated';
    case Rejected = 'rejected';
    case Confirmed = 'confirmed';
    case ExpiredUnconfirmed = 'expired_unconfirmed';

    /** Terminal states never change again (DB trigger is the backstop). */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Confirmed, self::Rejected, self::InvalidFormat, self::ExpiredUnconfirmed => true,
            self::Captured, self::Validating, self::Validated => false,
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Captured => $to === self::Validating || $to === self::InvalidFormat,
            self::Validating => $to === self::Validated || $to === self::Rejected,
            // `validated -> rejected` is required by Plan §58B.5 R-04: a code can be perfectly
            // valid while the *attribution* is refused at confirm time (another referrer is already
            // effective for this merchant). The §25.6 diagram draws `rejected` off `validating`
            // only, but §58A.1 states the confirm results drive `rejected` too. It is a forward move
            // into a terminal state, so it breaks neither the no-regression rule nor the DB trigger.
            self::Validated => $to === self::Confirmed
                || $to === self::ExpiredUnconfirmed
                || $to === self::Rejected,
            self::Confirmed, self::Rejected, self::InvalidFormat, self::ExpiredUnconfirmed => false,
        };
    }

    /**
     * Emission-scope gate (Plan §58B.1 data-minimization boundary): Servana streams merchant facts
     * to R&E only for merchants that actually carry a live referral claim. A malformed code was
     * never sent to R&E at all, and a rejected claim is settled — neither may leak further facts.
     */
    public function permitsEventEmission(): bool
    {
        return match ($this) {
            self::Captured, self::Validating, self::Validated, self::Confirmed, self::ExpiredUnconfirmed => true,
            self::InvalidFormat, self::Rejected => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
