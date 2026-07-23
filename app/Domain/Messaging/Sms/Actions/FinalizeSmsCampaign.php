<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Actions;

use App\Domain\Messaging\Sms\Enums\PersonnelSmsCampaignStatus;
use App\Domain\Messaging\Sms\Enums\PersonnelSmsRecipientDeliveryStatus;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\PersonnelSmsRecipient;
use App\Domain\Messaging\Sms\Services\PersonnelSmsBillingEntryFinalizer;
use App\Domain\Messaging\Sms\Services\PersonnelSmsCampaignStateMachine;
use App\Domain\Messaging\Sms\Support\SmsCostCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Settle a campaign from its recipient roll-up (Plan §64 "show final status"; Phase 21S).
 *
 * Called after every delivery outcome. It is a NO-OP while any recipient is still outstanding, and
 * a NO-OP once the campaign is terminal — so it is safe to call from every worker, as often as the
 * queue calls it.
 *
 * WHAT COUNTS AS OUTSTANDING depends on whether a provider DELIVERY RECEIPT channel exists
 * (`sms.delivery.receipts_enabled`, false in Phase 21S — see REM-SMS-002):
 *   - without receipts, `sent` (accepted by the provider) is the final knowledge Servana has, so it
 *     counts as success and only `pending` is outstanding;
 *   - with receipts, `sent` remains outstanding until a receipt resolves it to `delivered` or
 *     `failed`.
 * Servana NEVER claims `delivered` without a receipt.
 *
 * FINAL STATUS: all-success → `completed`; some success + some failure → `partially_failed`;
 * no success at all → `failed`. `final_cost_minor` and the billing entry are settled from the
 * recipients that were actually dispatched — a suppressed or opted-out recipient is never billed.
 */
final class FinalizeSmsCampaign
{
    public function __construct(
        private readonly PersonnelSmsCampaignStateMachine $state,
        private readonly PersonnelSmsBillingEntryFinalizer $billing,
        private readonly SmsCostCalculator $cost,
    ) {}

    public function handle(PersonnelSmsCampaign $campaign): PersonnelSmsCampaign
    {
        return DB::transaction(function () use ($campaign): PersonnelSmsCampaign {
            /** @var PersonnelSmsCampaign $locked */
            $locked = PersonnelSmsCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if ($locked->status->isTerminal()) {
                return $locked; // already settled; a late receipt never reopens a settled campaign
            }

            $counts = $this->recipientCounts($locked);

            if ($this->outstanding($counts) > 0) {
                return $locked;
            }

            $succeeded = $this->succeeded($counts);
            $failed = $counts[PersonnelSmsRecipientDeliveryStatus::Failed->value] ?? 0;

            if ($succeeded === 0 && $failed === 0) {
                return $locked; // nothing was ever dispatched (e.g. cancelled mid-flight)
            }

            $next = match (true) {
                $failed === 0 => PersonnelSmsCampaignStatus::Completed,
                $succeeded === 0 => PersonnelSmsCampaignStatus::Failed,
                default => PersonnelSmsCampaignStatus::PartiallyFailed,
            };

            $this->state->ensure($locked->status, $next);

            // Billable = every recipient actually handed to the provider (a permanently failed
            // submission still consumed provider capacity); suppressed/opted-out are never billed.
            $billableRecipients = $succeeded + $failed;
            $finalCostMinor = $this->cost->totalMinor($billableRecipients, $locked->segment_count);

            $locked->forceFill([
                'status' => $next,
                'final_cost_minor' => $finalCostMinor,
                'completed_at' => Carbon::now(),
                'failure_reason_code' => $next === PersonnelSmsCampaignStatus::Failed ? 'all_recipients_failed' : null,
            ])->save();

            $this->billing->settle($locked, $billableRecipients);

            return $locked;
        });
    }

    /** @return array<string, int> delivery status => count */
    private function recipientCounts(PersonnelSmsCampaign $campaign): array
    {
        /** @var array<string, int> $counts */
        $counts = PersonnelSmsRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->selectRaw('delivery_status, count(*) as total')
            ->groupBy('delivery_status')
            ->pluck('total', 'delivery_status')
            ->map(static fn ($total): int => (int) $total)
            ->all();

        return $counts;
    }

    /** @param array<string, int> $counts */
    private function outstanding(array $counts): int
    {
        $outstanding = $counts[PersonnelSmsRecipientDeliveryStatus::Pending->value] ?? 0;

        if ((bool) config('sms.delivery.receipts_enabled', false)) {
            $outstanding += $counts[PersonnelSmsRecipientDeliveryStatus::Sent->value] ?? 0;
        }

        return $outstanding;
    }

    /** @param array<string, int> $counts */
    private function succeeded(array $counts): int
    {
        $delivered = $counts[PersonnelSmsRecipientDeliveryStatus::Delivered->value] ?? 0;

        if ((bool) config('sms.delivery.receipts_enabled', false)) {
            return $delivered;
        }

        return $delivered + ($counts[PersonnelSmsRecipientDeliveryStatus::Sent->value] ?? 0);
    }
}
