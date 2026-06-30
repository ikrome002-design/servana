<?php

declare(strict_types=1);

namespace App\Domain\Compensation\Services;

use App\Domain\Compensation\ValueObjects\CommissionPreviewResult;
use App\Domain\Scheduling\Models\ServiceSession;

/**
 * Produces the NON-PAYABLE commission preview at service-session completion (Plan
 * §80 Phase 16C; Gate D). The preview is never earned, validated, or payable, and
 * this service NEVER writes a `commission_ledger`, `commission_rules`, compensation
 * plan, or payout liability.
 *
 * Compensation configuration (rules, plans) is owned by Phases 20F/20G and does not
 * exist yet — so every Phase 16C preview is `not_configured`. When the
 * authoritative configuration genuinely lands, this single seam resolves the
 * applicable plan and returns a calculated/`not_applicable`/`unavailable` preview;
 * "not configured" is never represented as a zero amount. Only validated payment in
 * the later payment/compensation workflow may create earned commission.
 */
final class CommissionPreviewService
{
    public function previewForCompletion(ServiceSession $session): CommissionPreviewResult
    {
        // No authoritative compensation configuration exists in Phase 16C (the
        // commission_rules / compensation plan tables are owned by Phases 20F/20G).
        // Returning an explicit typed "not configured" — NOT a zero amount, NOT an
        // earned/payable status, NO ledger row.
        return CommissionPreviewResult::notConfigured();
    }
}
