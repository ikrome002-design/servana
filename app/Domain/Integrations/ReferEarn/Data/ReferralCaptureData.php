<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Data;

use App\Domain\Integrations\ReferEarn\Enums\ReferralCaptureChannel;

/**
 * The referral inputs a registration request may carry (Plan §58A.1, §12.1 item 5; Phase 21R-A).
 *
 * Built by `RegisterMerchantRequest` from validated input only. It is deliberately tiny and
 * value-shaped: the registration action receives a referral *intent*, not a request object, so the
 * capture step cannot reach back into headers, cookies or the session for anything the allowlist
 * did not sanction.
 */
final readonly class ReferralCaptureData
{
    /** @param array<string, string>|null $landingMetadata already allowlist-filtered */
    public function __construct(
        public string $submittedCode,
        public ReferralCaptureChannel $channel,
        public ?array $landingMetadata = null,
    ) {}
}
