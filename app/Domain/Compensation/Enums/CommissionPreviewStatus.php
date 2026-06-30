<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Enums;

/**
 * The kind of non-payable commission preview produced at service-session completion
 * (Plan §80 Phase 16C; Gate D). A preview is NEVER earned, validated, or payable —
 * compensation rules, plans, the ledger, earned commission, and payouts are owned by
 * later phases (20F/20G/20H). This enum only distinguishes how to present the
 * preview; it never represents "not configured" as a zero amount.
 */
enum CommissionPreviewStatus: string
{
    /** A calculated preview amount is available (only when authoritative config exists). */
    case Available = 'available';

    /** The personnel member is salary-only — commission does not apply. */
    case NotApplicable = 'not_applicable';

    /** No authoritative compensation configuration exists yet (Phases 20F/20G). */
    case NotConfigured = 'not_configured';

    /** The preview cannot be produced for another safe, explicit reason. */
    case Unavailable = 'unavailable';
}
