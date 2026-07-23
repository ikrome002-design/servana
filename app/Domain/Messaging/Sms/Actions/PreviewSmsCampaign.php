<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Messaging\Sms\Support\SmsBatchLimiter;
use App\Domain\Messaging\Sms\Support\SmsCostCalculator;
use App\Domain\Messaging\Sms\Support\SmsMessageSegmentCalculator;
use App\Domain\Messaging\Sms\Support\SmsRecipientEligibilityEvaluator;
use App\Domain\Messaging\Sms\ValueObjects\SmsCampaignPreview;
use App\Models\User;

/**
 * Server-authoritative SMS preview (Plan §64: *"backend revalidates every recipient at preview"*;
 * Phase 21S).
 *
 * ADVISORY, NOT AUTHORITATIVE. This action deliberately:
 *   - creates NO campaign and NO recipient rows;
 *   - sends NOTHING to the provider;
 *   - creates NO billing entry;
 *   - trusts NOTHING the client computed — the recipient list, the segment count and the cost are
 *     all recomputed here from the submitted body and ULIDs.
 *
 * The confirm path runs the SAME evaluator again, so a consent withdrawal, an archival or a lost
 * session between preview and confirm changes the outcome and confirmation always wins.
 *
 * It IS audited (`personnel.sms.previewed`, info) even though it changes nothing: Plan §64 requires
 * enumeration-pattern detection, and a repeated preview against many unknown ULIDs is exactly that
 * signal. The context carries counts and safe reason codes only — never a client identity, never a
 * phone, never the message body (ADR-010, Plan §24.5).
 */
final class PreviewSmsCampaign
{
    public function __construct(
        private readonly SmsRecipientEligibilityEvaluator $evaluator,
        private readonly SmsMessageSegmentCalculator $segments,
        private readonly SmsCostCalculator $cost,
        private readonly SmsBatchLimiter $limiter,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  list<string>  $clientUlids
     */
    public function handle(StaffProfile $profile, array $clientUlids, string $body, User $actor): SmsCampaignPreview
    {
        // Server-side batch + composition caps (never the frontend's job).
        $this->limiter->ensureWithinRecipientLimit(count($clientUlids));

        $measurement = $this->segments->measure($body);
        $this->limiter->ensureWithinMessageLimits($measurement->characterCount, $measurement->segmentCount);

        $evaluation = $this->evaluator->evaluate($profile, $clientUlids);
        $estimatedCost = $this->cost->total($evaluation->eligibleCount(), $measurement->segmentCount);

        $this->audit->record(
            AuditEvent::PersonnelSmsPreviewed,
            $actor,
            $profile->merchant_id,
            $profile->primary_branch_id,
            $profile,
            [
                'staff_profile_ulid' => $profile->ulid,
                'selected_count' => count($clientUlids),
                'recipient_count' => $evaluation->eligibleCount(),
                'excluded_count' => $evaluation->excludedCount(),
                'excluded_reason_codes' => $evaluation->exclusionCounts(),
                'segment_count' => $measurement->segmentCount,
                'estimated_cost_minor' => $estimatedCost->minorUnits,
                'currency' => $estimatedCost->currency->value,
            ],
        );

        return new SmsCampaignPreview(
            recipientCount: $evaluation->eligibleCount(),
            excludedCount: $evaluation->excludedCount(),
            exclusionCounts: $evaluation->exclusionCounts(),
            characterCount: $measurement->characterCount,
            segmentCount: $measurement->segmentCount,
            requiresUnicode: $measurement->requiresUnicode,
            charactersRemainingInSegment: $measurement->charactersRemainingInSegment,
            estimatedCost: $estimatedCost,
            unitCostMinor: $this->cost->unitCostMinor(),
            maxRecipients: $this->limiter->maxRecipients(),
            maxMessageCharacters: $this->limiter->maxMessageCharacters(),
        );
    }
}
