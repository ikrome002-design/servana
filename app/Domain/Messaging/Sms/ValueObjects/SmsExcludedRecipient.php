<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\ValueObjects;

use App\Domain\Clients\Models\Client;
use App\Domain\Messaging\Sms\Enums\SmsConsentSnapshotStatus;
use App\Domain\Messaging\Sms\Enums\SmsRecipientExclusionReason;

/**
 * One client excluded from a campaign, with the safe reason code (Plan §64; ADR-010; Phase 21S).
 *
 * `client` is present ONLY when the exclusion is about a client the acting personnel legitimately
 * served or may see — that is what lets the confirm action persist a visible `suppressed` /
 * `opted_out` recipient snapshot so the merchant can see why a send did not happen. For
 * `unknown_client` and `not_served` it is null, because persisting a row for a client this
 * personnel has no relationship with would itself be a contact record.
 *
 * This object is never serialized as-is: the preview response groups exclusions BY REASON with a
 * count, never as a per-client list.
 */
final readonly class SmsExcludedRecipient
{
    public function __construct(
        public SmsRecipientExclusionReason $reason,
        public ?Client $client = null,
        public ?int $serviceSessionId = null,
        /**
         * The consent state observed for this client, recorded truthfully even when the exclusion
         * was for another reason (an archived client may well be opted in). Null when no client
         * was resolved at all.
         */
        public ?SmsConsentSnapshotStatus $consentStatus = null,
    ) {}

    /** Whether a suppressed recipient snapshot should be persisted for this exclusion. */
    public function isSnapshotted(): bool
    {
        return $this->client !== null;
    }
}
