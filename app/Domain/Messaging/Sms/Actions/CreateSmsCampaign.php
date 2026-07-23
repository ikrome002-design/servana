<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Enums\SmsConsentSnapshotStatus;
use App\Domain\Messaging\Sms\Exceptions\NoEligibleSmsRecipientsException;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Support\SmsBatchLimiter;
use App\Domain\Messaging\Sms\Support\SmsCostCalculator;
use App\Domain\Messaging\Sms\Support\SmsMessageSegmentCalculator;
use App\Domain\Messaging\Sms\Support\SmsRecipientEligibilityEvaluator;
use App\Domain\Messaging\Sms\ValueObjects\SmsEligibleRecipient;
use App\Domain\Messaging\Sms\ValueObjects\SmsExcludedRecipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create the DRAFT campaign and its immutable recipient snapshots (Plan §64; Phase 21S).
 *
 * This is the composition step: the campaign row plus one recipient row per selected client that
 * this personnel member is allowed to have a row for. Nothing is billed, nothing is queued and
 * nothing is sent — {@see ConfirmSmsCampaign} is the commitment point.
 *
 * WHAT GETS A ROW, AND WHY:
 *   - an ELIGIBLE client gets a `pending` row carrying the encrypted delivery snapshot of their
 *     number and the completed session that evidences the relationship;
 *   - a client the personnel DID serve but who cannot receive (archived, opted out, no consent on
 *     record) gets a visible `suppressed`/`opted_out` row with NO phone snapshot at all, so the
 *     merchant can see why the send did not happen (Plan §74 data minimization);
 *   - `unknown_client`, `not_served` and `duplicate_selection` get NO row — persisting one would
 *     create a contact record for a client this personnel has no relationship with, which is
 *     exactly what ADR-010 forbids. They are counted, never listed.
 *
 * The whole write is ONE transaction, so a campaign never exists without its snapshots. Ownership,
 * consent and the batch cap are all re-evaluated here rather than trusted from the preview.
 */
final class CreateSmsCampaign
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
     *
     * @throws NoEligibleSmsRecipientsException
     */
    public function handle(StaffProfile $profile, array $clientUlids, string $body, User $actor): PersonnelSmsCampaign
    {
        $this->limiter->ensureWithinRecipientLimit(count($clientUlids));

        $measurement = $this->segments->measure($body);
        $this->limiter->ensureWithinMessageLimits($measurement->characterCount, $measurement->segmentCount);

        $evaluation = $this->evaluator->evaluate($profile, $clientUlids);

        if ($evaluation->eligibleCount() === 0) {
            throw new NoEligibleSmsRecipientsException($evaluation->exclusionCounts());
        }

        $estimatedCost = $this->cost->total($evaluation->eligibleCount(), $measurement->segmentCount);

        return DB::transaction(function () use ($profile, $body, $measurement, $evaluation, $estimatedCost, $actor): PersonnelSmsCampaign {
            $campaign = PersonnelSmsCampaign::query()->create([
                'merchant_id' => $profile->merchant_id,
                // The campaign belongs to the personnel member's HOME branch. A recipient row
                // carries the CLIENT's own branch, which may differ when a membership is active in
                // more than one branch — both are inside the merchant and both composite FKs hold.
                'branch_id' => $profile->primary_branch_id,
                'staff_profile_id' => $profile->id,
                'message_body_encrypted' => $body,
                'message_template_id' => null,
                'recipient_count' => $evaluation->eligibleCount(),
                'message_character_count' => $measurement->characterCount,
                'segment_count' => $measurement->segmentCount,
                'estimated_cost_minor' => $estimatedCost->minorUnits,
                'currency' => $estimatedCost->currency->value,
                'status' => PersonnelSmsCampaignStatus::Draft,
                'created_by' => $actor->id,
            ]);

            foreach ($evaluation->eligible as $eligible) {
                $this->snapshotEligible($campaign, $eligible);
            }

            foreach ($evaluation->snapshottableExclusions() as $excluded) {
                $this->snapshotExcluded($campaign, $excluded);
            }

            $this->audit->record(
                AuditEvent::PersonnelSmsCampaignCreated,
                $actor,
                $campaign->merchant_id,
                $campaign->branch_id,
                $campaign,
                [
                    'campaign_ulid' => $campaign->ulid,
                    'staff_profile_ulid' => $profile->ulid,
                    'recipient_count' => $evaluation->eligibleCount(),
                    'excluded_count' => $evaluation->excludedCount(),
                    'excluded_reason_codes' => $evaluation->exclusionCounts(),
                    'segment_count' => $measurement->segmentCount,
                    'estimated_cost_minor' => $estimatedCost->minorUnits,
                    'currency' => $estimatedCost->currency->value,
                ],
            );

            $suppressedCount = count($evaluation->snapshottableExclusions());

            if ($suppressedCount > 0) {
                // ONE aggregate event, not one per client: a per-client audit row would itself be a
                // record of who this personnel member tried to contact (ADR-010).
                $this->audit->record(
                    AuditEvent::PersonnelSmsRecipientSuppressed,
                    $actor,
                    $campaign->merchant_id,
                    $campaign->branch_id,
                    $campaign,
                    [
                        'campaign_ulid' => $campaign->ulid,
                        'suppressed_count' => $suppressedCount,
                        'excluded_reason_codes' => $evaluation->exclusionCounts(),
                    ],
                );
            }

            return $campaign;
        });
    }

    private function snapshotEligible(PersonnelSmsCampaign $campaign, SmsEligibleRecipient $eligible): void
    {
        PersonnelSmsRecipient::query()->create([
            'merchant_id' => $eligible->client->merchant_id,
            'branch_id' => $eligible->client->branch_id,
            'campaign_id' => $campaign->id,
            'client_id' => $eligible->client->id,
            'service_session_id' => $eligible->serviceSessionId,
            // The ONE place a client's full number is copied. Encrypted at rest by the model cast,
            // `$hidden` from every representation, read only by the delivery job.
            'phone_encrypted' => $eligible->client->phone_encrypted,
            'phone_last_four' => $eligible->client->phone_last_four,
            'eligibility_snapshot_json' => [
                'served' => true,
                'service_session_id_present' => $eligible->serviceSessionId !== null,
                'client_status' => $eligible->client->status->value,
                'consent' => $eligible->consentStatus->value,
            ],
            'consent_status_snapshot' => $eligible->consentStatus,
            'delivery_status' => PersonnelSmsRecipientDeliveryStatus::Pending,
        ]);
    }

    private function snapshotExcluded(PersonnelSmsCampaign $campaign, SmsExcludedRecipient $excluded): void
    {
        // Non-null by construction: snapshottableExclusions() filters on isSnapshotted().
        $client = $excluded->client;

        if ($client === null) {
            return;
        }

        PersonnelSmsRecipient::query()->create([
            'merchant_id' => $client->merchant_id,
            'branch_id' => $client->branch_id,
            'campaign_id' => $campaign->id,
            'client_id' => $client->id,
            'service_session_id' => $excluded->serviceSessionId,
            // NO delivery snapshot: an excluded recipient is never dispatched, so their number is
            // never copied (Plan §74). Only the masked last-four is retained for display.
            'phone_encrypted' => null,
            'phone_last_four' => $client->phone_last_four,
            'eligibility_snapshot_json' => [
                'served' => $excluded->serviceSessionId !== null,
                'client_status' => $client->status->value,
                'exclusion_reason' => $excluded->reason->value,
            ],
            // The observed consent state, recorded truthfully — an archived client excluded for
            // archival may still be opted in, and the snapshot must say so.
            'consent_status_snapshot' => $excluded->consentStatus ?? SmsConsentSnapshotStatus::Missing,
            'delivery_status' => $excluded->reason->recipientStatus(),
        ]);
    }
}
