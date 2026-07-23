<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Enums;

/**
 * Normalized classification of one SMS provider response (Plan §64 "retry transient (capped
 * backoff), not permanent invalid/opt-out failures"; Phase 21S). Mirrors the
 * `sms_delivery_attempts.result_class` DB CHECK exactly.
 *
 * This is the RETRY DECISION INPUT, stored on every attempt row, so the policy is provable from
 * the database without a live provider — the same design as `re_event_deliveries.response_class`
 * (21R-A). A provider adapter maps its own vendor codes onto this closed set; an unrecognised
 * response maps to {@see self::Unexpected}, which is retriable-with-cap so an unknown provider
 * behaviour degrades to a dead letter rather than silently dropping a message.
 *
 * NO PHONE NUMBER, credential or message body is ever carried alongside this class — the adapter
 * hands back only this classification, a bounded provider code and a redacted message.
 */
enum SmsProviderResultClass: string
{
    case Accepted = 'accepted';
    /** The provider rejected the destination outright (malformed/unreachable MSISDN). */
    case InvalidRecipient = 'invalid_recipient';
    /** The provider knows the subscriber has opted out of this sender. */
    case OptedOut = 'opted_out';
    case RateLimited = 'rate_limited';
    case InsufficientBalance = 'insufficient_balance';
    case ProviderError = 'provider_error';
    case TransportError = 'transport_error';
    case Unauthorized = 'unauthorized';
    case Unexpected = 'unexpected';

    /**
     * All backing values, in canonical order — the authoritative list for the DB CHECK and every
     * parity assertion.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * PERMANENT failures are never retried (Plan §64). Only the two recipient-scoped rejections
     * qualify: retrying an invalid number or an opted-out subscriber can never succeed and, in the
     * opt-out case, retrying would be a consent violation.
     */
    public function isPermanentFailure(): bool
    {
        return match ($this) {
            self::InvalidRecipient, self::OptedOut => true,
            default => false,
        };
    }

    /**
     * TRANSIENT failures are retried with capped backoff. `unauthorized` and `insufficient_balance`
     * are operator-side conditions: retrying is correct once the operator fixes them, and the
     * attempt trail plus the dead-letter audit event is how they find out.
     */
    public function isTransientFailure(): bool
    {
        return $this !== self::Accepted && ! $this->isPermanentFailure();
    }

    public function isAccepted(): bool
    {
        return $this === self::Accepted;
    }

    /** The attempt outcome recorded for this class. */
    public function attemptStatus(): SmsDeliveryAttemptStatus
    {
        return match (true) {
            $this->isAccepted() => SmsDeliveryAttemptStatus::Accepted,
            $this->isPermanentFailure() => SmsDeliveryAttemptStatus::PermanentFailure,
            default => SmsDeliveryAttemptStatus::TransientFailure,
        };
    }

    /**
     * The terminal recipient status a PERMANENT failure produces. A provider-reported opt-out is
     * recorded as an opt-out (a consent fact), not a generic failure.
     */
    public function permanentRecipientStatus(): PersonnelSmsRecipientDeliveryStatus
    {
        return match ($this) {
            self::OptedOut => PersonnelSmsRecipientDeliveryStatus::OptedOut,
            default => PersonnelSmsRecipientDeliveryStatus::Failed,
        };
    }

    /**
     * Classes that indicate an operator/configuration problem rather than a recipient problem —
     * these carry a high-severity audit signal when they exhaust their retries.
     */
    public function isOperatorCondition(): bool
    {
        return match ($this) {
            self::Unauthorized, self::InsufficientBalance => true,
            default => false,
        };
    }
}
