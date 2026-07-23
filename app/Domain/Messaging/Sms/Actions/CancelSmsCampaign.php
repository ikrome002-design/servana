<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Enums\SmsRecipientExclusionReason;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Services\PersonnelSmsBillingEntryFinalizer;
use App\Domain\Messaging\Sms\Services\PersonnelSmsCampaignStateMachine;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a campaign that has not yet been handed to the provider (Plan §64; Phase 21S).
 *
 * Cancellable from `draft`, `confirmed` and `queued` only — once a campaign is `sending`, some
 * messages have already left, and Servana will not pretend otherwise. The state machine rejects
 * anything else with 422 `invalid_state_transition`.
 *
 * Every still-`pending` recipient is suppressed (they were never dispatched, so nothing is
 * un-sent), and the live billing entry is cancelled, so a cancelled campaign owes nothing. Both
 * happen in the same transaction as the status change.
 */
final class CancelSmsCampaign
{
    public function __construct(
        private readonly PersonnelSmsCampaignStateMachine $campaignState,
        private readonly SuppressSmsRecipient $suppress,
        private readonly PersonnelSmsBillingEntryFinalizer $billing,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PersonnelSmsCampaign $campaign, User $actor): PersonnelSmsCampaign
    {
        return DB::transaction(function () use ($campaign, $actor): PersonnelSmsCampaign {
            /** @var PersonnelSmsCampaign $locked */
            $locked = PersonnelSmsCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

            $this->campaignState->ensure($locked->status, PersonnelSmsCampaignStatus::Cancelled);

            /** @var Collection<int, PersonnelSmsRecipient> $pending */
            $pending = PersonnelSmsRecipient::query()
                ->where('campaign_id', $locked->id)
                ->where('delivery_status', PersonnelSmsRecipientDeliveryStatus::Pending->value)
                ->get();

            foreach ($pending as $recipient) {
                // Cancellation is not a consent decision, so these become `suppressed`, never
                // `opted_out` — the merchant must be able to tell the two apart.
                $this->suppress->handle($recipient, SmsRecipientExclusionReason::CampaignCancelled);
            }

            $locked->forceFill([
                'status' => PersonnelSmsCampaignStatus::Cancelled,
                'cancelled_at' => Carbon::now(),
            ])->save();

            // A cancelled campaign owes nothing for the messages it never sent.
            $this->billing->cancel($locked);

            $this->audit->record(
                AuditEvent::PersonnelSmsCampaignCancelled,
                $actor,
                $locked->merchant_id,
                $locked->branch_id,
                $locked,
                [
                    'campaign_ulid' => $locked->ulid,
                    'suppressed_count' => $pending->count(),
                    'recipient_count' => $locked->recipient_count,
                ],
            );

            return $locked;
        });
    }
}
