<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Actions;

use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Jobs\DeliverSmsRecipientJob;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Services\PersonnelSmsCampaignStateMachine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Move a confirmed campaign to `queued` and dispatch one delivery job per pending recipient
 * (Plan §64 "queue delivery"; Phase 21S).
 *
 * ALWAYS CALLED AFTER COMMIT. {@see ConfirmSmsCampaign} does its whole write inside a transaction
 * and this runs in the `afterCommit` callback, so a rolled-back confirmation can never leave a
 * dispatched job behind and a worker can never read a half-written campaign.
 *
 * IDEMPOTENT: only a `confirmed` campaign is queued. A repeat call for a campaign already at
 * `queued`/`sending`/settled does nothing, so a duplicate confirm (or a retried afterCommit hook)
 * cannot dispatch a second round of sends. The per-recipient jobs are themselves guarded by the
 * recipient's own `pending` status.
 */
final class QueueSmsCampaign
{
    public function __construct(private readonly PersonnelSmsCampaignStateMachine $state) {}

    public function handle(PersonnelSmsCampaign $campaign): PersonnelSmsCampaign
    {
        /** @var PersonnelSmsCampaign|null $queued */
        $queued = DB::transaction(function () use ($campaign): ?PersonnelSmsCampaign {
            /** @var PersonnelSmsCampaign $locked */
            $locked = PersonnelSmsCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if ($locked->status !== PersonnelSmsCampaignStatus::Confirmed) {
                return null; // already queued/sending/settled, or cancelled — nothing to do
            }

            $this->state->ensure($locked->status, PersonnelSmsCampaignStatus::Queued);

            $locked->forceFill([
                'status' => PersonnelSmsCampaignStatus::Queued,
                'queued_at' => Carbon::now(),
            ])->save();

            return $locked;
        });

        if ($queued === null) {
            return $campaign->refresh();
        }

        PersonnelSmsRecipient::query()
            ->where('campaign_id', $queued->id)
            ->where('delivery_status', PersonnelSmsRecipientDeliveryStatus::Pending->value)
            ->select(['id', 'merchant_id'])
            ->each(static function (PersonnelSmsRecipient $recipient) use ($queued): void {
                // Merchant-scoped, branch-wide: a campaign's recipients may span more than one
                // branch when a membership is active in several, and the delivery job needs to
                // read all of them.
                DeliverSmsRecipientJob::dispatch($queued->merchant_id, $recipient->id);
            });

        return $queued;
    }
}
