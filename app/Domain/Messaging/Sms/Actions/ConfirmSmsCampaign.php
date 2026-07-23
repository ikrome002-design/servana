<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Clients\Enums\ClientStatus;
use App\Domain\Clients\Enums\ConsentChannel;
use App\Domain\Clients\Enums\ConsentState;
use App\Domain\Clients\Models\ClientConsent;
use App\Domain\Hr\Models\StaffProfile;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Enums\SmsRecipientExclusionReason;
use App\Domain\Messaging\Sms\Exceptions\NoEligibleSmsRecipientsException;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Services\PersonnelSmsCampaignStateMachine;
use App\Domain\Messaging\Sms\Support\ServedClientSelector;
use App\Domain\Messaging\Sms\Support\SmsCostCalculator;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The COMMITMENT POINT of a Personnel SMS campaign (Plan §64: *"personnel confirms explicitly;
 * backend revalidates entitlement/billing status/own-scope/consent/cost; create campaign/recipient
 * snapshots transactionally; queue delivery"*; Phase 21S).
 *
 * Everything happens in ONE transaction under a row lock on the campaign:
 *   1. re-derive eligibility for EVERY still-pending recipient from live data — the completed-session
 *      relationship, the client's status and the consent row are all read again, so a withdrawal,
 *      an archival or a lost session between draft and confirm suppresses that recipient here;
 *   2. suppress the recipients that no longer qualify (their snapshot rows move to
 *      `opted_out`/`suppressed` through the recipient state machine, never by assignment);
 *   3. refuse the whole confirmation if nothing survives (422 `no_eligible_recipients`) — a
 *      campaign is never queued or billed with zero recipients;
 *   4. re-price from the surviving count (the client's estimate is never trusted);
 *   5. snapshot consent (`consent_snapshot_at`), stamp `confirmed_at`, move `draft -> confirmed`;
 *   6. create the single provisional billing entry.
 *
 * Delivery is queued by {@see QueueSmsCampaign} AFTER COMMIT, never inside the transaction, so a
 * rolled-back confirmation can never leave a dispatched job behind.
 *
 * DUPLICATE CONFIRM SENDS ONCE, three ways over:
 *   - `EnsureIdempotentRequest` replays the stored response for a repeated `Idempotency-Key`;
 *   - this action is an idempotent NO-OP for a campaign already at or past `confirmed` — it
 *     returns the campaign untouched, creating no recipients, no billing entry and no dispatch;
 *   - `sms_billing_entries_live_campaign_unique` makes a second live charge impossible in the
 *     database even under concurrency.
 */
final class ConfirmSmsCampaign
{
    public function __construct(
        private readonly ServedClientSelector $servedClients,
        private readonly SmsCostCalculator $cost,
        private readonly PersonnelSmsCampaignStateMachine $campaignState,
        private readonly SuppressSmsRecipient $suppress,
        private readonly CreateSmsBillingEntry $billing,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @throws NoEligibleSmsRecipientsException
     */
    public function handle(PersonnelSmsCampaign $campaign, User $actor): PersonnelSmsCampaign
    {
        return DB::transaction(function () use ($campaign, $actor): PersonnelSmsCampaign {
            /** @var PersonnelSmsCampaign $locked */
            $locked = PersonnelSmsCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

            // Idempotent replay: already committed, so do nothing at all and return as-is.
            if ($locked->status !== PersonnelSmsCampaignStatus::Draft) {
                if ($locked->status->isTerminal()) {
                    // cancelled/failed/completed can never be confirmed — fail closed with 422.
                    $this->campaignState->ensure($locked->status, PersonnelSmsCampaignStatus::Confirmed);
                }

                return $locked;
            }

            $this->campaignState->ensure($locked->status, PersonnelSmsCampaignStatus::Confirmed);

            [$survivors, $suppressedCounts] = $this->revalidateRecipients($locked);

            if ($survivors === 0) {
                // Rolls the transaction back: no confirmation, no billing entry, no dispatch.
                throw new NoEligibleSmsRecipientsException($suppressedCounts);
            }

            if ($suppressedCounts !== []) {
                // ONE aggregate event — a per-client audit row would itself record who this
                // personnel member tried to contact (ADR-010).
                $this->audit->record(
                    AuditEvent::PersonnelSmsRecipientSuppressed,
                    $actor,
                    $locked->merchant_id,
                    $locked->branch_id,
                    $locked,
                    [
                        'campaign_ulid' => $locked->ulid,
                        'suppressed_count' => array_sum($suppressedCounts),
                        'excluded_reason_codes' => $suppressedCounts,
                        'stage' => 'confirm_revalidation',
                    ],
                );
            }

            $now = Carbon::now();
            $estimatedCostMinor = $this->cost->totalMinor($survivors, $locked->segment_count);

            $locked->forceFill([
                'recipient_count' => $survivors,
                'estimated_cost_minor' => $estimatedCostMinor,
                'status' => PersonnelSmsCampaignStatus::Confirmed,
                'consent_snapshot_at' => $now,
                'confirmed_at' => $now,
            ])->save();

            $entry = $this->billing->handle($locked, $actor);

            $this->audit->record(
                AuditEvent::PersonnelSmsCampaignConfirmed,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'campaign_ulid' => $locked->ulid,
                    'recipient_count' => $survivors,
                    'segment_count' => $locked->segment_count,
                    'estimated_cost_minor' => $estimatedCostMinor,
                    'currency' => $locked->currency,
                    'billing_entry_ulid' => $entry->ulid,
                ],
            );

            return $locked;
        });
    }

    /**
     * Re-derive eligibility for every still-pending recipient and suppress the ones that no longer
     * qualify.
     *
     * @return array{0: int, 1: array<string, int>} surviving count, suppressed reason-code counts
     */
    private function revalidateRecipients(PersonnelSmsCampaign $campaign): array
    {
        /** @var StaffProfile|null $profile */
        $profile = StaffProfile::query()->whereKey($campaign->staff_profile_id)->first();

        /** @var Collection<int, PersonnelSmsRecipient> $pending */
        $pending = PersonnelSmsRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('delivery_status', PersonnelSmsRecipientDeliveryStatus::Pending->value)
            ->with('client')
            ->get();

        if ($profile === null || $pending->isEmpty()) {
            return [0, []];
        }

        /** @var list<int> $clientIds */
        $clientIds = array_values(array_map(
            static fn (PersonnelSmsRecipient $r): int => $r->client_id,
            $pending->all(),
        ));

        // Both re-reads are single grouped queries — never a lookup per recipient (Plan §72).
        $sessions = $this->servedClients->evidencingSessionIds($profile, $clientIds);
        $consents = $this->currentConsents($clientIds);

        $survivors = 0;
        $suppressed = [];

        foreach ($pending as $recipient) {
            $reason = $this->disqualify($recipient, $sessions, $consents);

            if ($reason === null) {
                $survivors++;

                continue;
            }

            if ($this->suppress->handle($recipient, $reason)) {
                $suppressed[$reason->value] = ($suppressed[$reason->value] ?? 0) + 1;
            }
        }

        return [$survivors, $suppressed];
    }

    /**
     * The reason this recipient can no longer be sent to, or null when it still qualifies.
     *
     * @param  array<int, int>  $sessions
     * @param  array<int, ConsentState>  $consents
     */
    private function disqualify(
        PersonnelSmsRecipient $recipient,
        array $sessions,
        array $consents,
    ): ?SmsRecipientExclusionReason {
        $client = $recipient->client;

        if ($client === null) {
            return SmsRecipientExclusionReason::UnknownClient;
        }

        if (! isset($sessions[$recipient->client_id])) {
            return SmsRecipientExclusionReason::NotServed;
        }

        if ($client->status !== ClientStatus::Active) {
            return SmsRecipientExclusionReason::ClientArchived;
        }

        $consent = $consents[$recipient->client_id] ?? null;

        if ($consent === null) {
            return SmsRecipientExclusionReason::ConsentMissing;
        }

        if ($consent !== ConsentState::OptedIn) {
            return SmsRecipientExclusionReason::ConsentOptedOut;
        }

        return null;
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, ConsentState>
     */
    private function currentConsents(array $clientIds): array
    {
        $states = [];

        /** @var ClientConsent $consent */
        foreach (ClientConsent::query()
            ->whereIn('client_id', $clientIds)
            ->where('channel', ConsentChannel::Sms->value)
            ->get() as $consent) {
            $states[$consent->client_id] = $consent->state;
        }

        return $states;
    }
}
