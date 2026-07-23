<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Enums;

/**
 * Outcome recorded on one append-only `sms_delivery_attempts` row (Plan §13.13; Phase 21S).
 * Mirrors the `sms_delivery_attempts.status` DB CHECK exactly.
 *
 * The attempt row is evidence, not a lifecycle — there are no transitions. The retry decision is
 * derived from {@see SmsProviderResultClass}, which is stored alongside it.
 */
enum SmsDeliveryAttemptStatus: string
{
    case Accepted = 'accepted';
    case TransientFailure = 'transient_failure';
    case PermanentFailure = 'permanent_failure';

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

    /** Only a transient failure may schedule a retry (DB CHECK enforces the same rule). */
    public function mayScheduleRetry(): bool
    {
        return $this === self::TransientFailure;
    }
}
