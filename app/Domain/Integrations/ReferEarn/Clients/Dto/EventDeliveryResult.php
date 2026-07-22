<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Clients\Dto;

use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryResponseClass;

/**
 * The outcome of ONE delivery attempt (Plan §58A.2; Phase 21R-A).
 *
 * The classification, not the raw status code, is what the outbox acts on — so the retry /
 * dead-letter / pause policy is fully testable without a live partner. The body carried here is
 * already redacted and bounded by the client, so nothing downstream can persist or log a secret.
 */
final readonly class EventDeliveryResult
{
    public function __construct(
        public ReDeliveryResponseClass $class,
        public ?int $status,
        public ?string $errorCode,
        public ?string $redactedBody,
        public int $durationMs,
        /** `Retry-After` in seconds when the partner supplied one (429/503). */
        public ?int $retryAfterSeconds = null,
    ) {}

    public function isAccepted(): bool
    {
        return $this->class === ReDeliveryResponseClass::Accepted;
    }
}
