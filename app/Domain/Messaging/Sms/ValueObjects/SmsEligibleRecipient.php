<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\ValueObjects;

use App\Domain\Clients\Models\Client;
use App\Domain\Messaging\Sms\Enums\SmsConsentSnapshotStatus;

/**
 * One client that passed every eligibility gate (Plan §64; Phase 21S).
 *
 * Carries the Client MODEL, not a phone number: the confirm action reads
 * `$client->phone_encrypted` exactly once, inside the snapshot transaction, to write the delivery
 * snapshot. Nothing else in the phase reads it, and this object is never serialized.
 */
final readonly class SmsEligibleRecipient
{
    public function __construct(
        public Client $client,
        /** The completed session that evidences the served-client relationship. */
        public ?int $serviceSessionId,
        public SmsConsentSnapshotStatus $consentStatus,
    ) {}
}
