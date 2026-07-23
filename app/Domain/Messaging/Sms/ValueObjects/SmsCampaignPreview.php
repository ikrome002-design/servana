<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\ValueObjects;

use App\Support\Money;

/**
 * The server-authoritative result of an SMS preview (Plan §64: *"backend revalidates every
 * recipient at preview (returns recipient count, excluded count/reasons, estimated segments,
 * estimated KES cost, billing notice)"*; Phase 21S).
 *
 * CONTACT PROTECTION (ADR-010): every field here is a COUNT, a CODE or a MONEY value. There is no
 * per-client list, no name, no phone and no email — which is precisely what stops the preview
 * endpoint from becoming a contact-export surrogate or an enumeration oracle.
 *
 * The frontend renders these numbers; it never derives them.
 */
final readonly class SmsCampaignPreview
{
    /** @param array<string, int> $exclusionCounts reason code => count */
    public function __construct(
        public int $recipientCount,
        public int $excludedCount,
        public array $exclusionCounts,
        public int $characterCount,
        public int $segmentCount,
        public bool $requiresUnicode,
        public int $charactersRemainingInSegment,
        public Money $estimatedCost,
        public int $unitCostMinor,
        public int $maxRecipients,
        public int $maxMessageCharacters,
    ) {}
}
