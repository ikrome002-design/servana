<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Enums;

/**
 * How a referral code reached Servana at self-registration (Plan §13.17, §58A.1; Phase 21R-A).
 *
 * Mirrors the `referral_snapshots.capture_channel` DB CHECK. This is capture provenance only — it
 * carries no referrer identity and never affects attribution, which is R&E's decision.
 */
enum ReferralCaptureChannel: string
{
    /** `?ref=SERVANA-XXXXX` on the registration URL, forwarded by the SPA. */
    case QueryParam = 'query_param';

    /** Typed into the optional referral field on the registration form. */
    case ManualEntry = 'manual_entry';

    /** Arrived via a Citrus central redirect that forwarded the code. */
    case CentralRedirect = 'central_redirect';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
