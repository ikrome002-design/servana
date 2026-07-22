<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Enums;

/**
 * Classification of one delivery attempt's outcome (Plan §58A.2 response handling; Phase 21R-A).
 *
 * Mirrors the `re_event_deliveries.response_class` DB CHECK. The class — not the raw status code —
 * decides whether the outbox retries, dead-letters, or pauses, so the retry policy is testable
 * without a live partner.
 */
enum ReDeliveryResponseClass: string
{
    /** 202 — R&E accepted after a durable write. */
    case Accepted = 'accepted';

    /** 409 EVENT_ID_PAYLOAD_MISMATCH — tamper signal. Stop permanently; never mutate-and-resend. */
    case PayloadMismatch = 'payload_mismatch';

    /** 401/403 — credential problem. Pause the queue and alert; the event stays retriable. */
    case Unauthorized = 'unauthorized';

    /** 422 — schema rejection (contract drift). Dead-letter; the fix ships as a version bump. */
    case SchemaRejected = 'schema_rejected';

    /** 429 — honour Retry-After within the backoff cap. */
    case RateLimited = 'rate_limited';

    /** 5xx — transient server fault. */
    case ServerError = 'server_error';

    /** Connection/timeout/TLS failure — no HTTP status was observed. */
    case TransportError = 'transport_error';

    /** Any other status Servana has no pinned rule for; treated as retryable, never as success. */
    case Unexpected = 'unexpected';

    /** May this attempt be retried (subject to the max-age cap)? */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::ServerError, self::TransportError, self::Unexpected, self::Unauthorized => true,
            self::Accepted, self::PayloadMismatch, self::SchemaRejected => false,
        };
    }

    /** Does this outcome dead-letter the event immediately, regardless of attempt count? */
    public function isPermanentFailure(): bool
    {
        return match ($this) {
            self::PayloadMismatch, self::SchemaRejected => true,
            default => false,
        };
    }

    /**
     * Does this outcome indicate a credential problem that must pause the queue and alert
     * (Plan §58A.2: `401/403` → pause + alert)? The event itself stays retriable.
     */
    public function requiresCredentialAlert(): bool
    {
        return $this === self::Unauthorized;
    }

    public static function fromHttpStatus(int $status): self
    {
        return match (true) {
            $status === 202 => self::Accepted,
            $status === 409 => self::PayloadMismatch,
            $status === 401, $status === 403 => self::Unauthorized,
            $status === 422 => self::SchemaRejected,
            $status === 429 => self::RateLimited,
            $status >= 500 && $status <= 599 => self::ServerError,
            default => self::Unexpected,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
