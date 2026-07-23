<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Services;

use App\Domain\Messaging\Sms\Enums\SmsBillingEntryStatus;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\SmsBillingEntry;
use App\Domain\Messaging\Sms\Support\SmsCostCalculator;

/**
 * Settle a campaign's billable-SMS entry once delivery has finished (Plan §64; ADR-005; Phase 21S).
 *
 * The provisional entry created at confirm is priced from the recipients that were ELIGIBLE then.
 * By settlement the billable count can be lower — a provider-reported opt-out moves a recipient to
 * `opted_out` and it is never billed. Because `sms_billing_entries_guard` freezes every monetary
 * column, a changed quantity is NOT an in-place edit:
 *
 *   - quantity unchanged → the provisional entry simply transitions `provisional -> billable`;
 *   - quantity changed   → the provisional entry is `cancelled` and a NEW `billable` entry is
 *     written with the correct quantity, leaving both rows as an auditable trail of the correction;
 *   - nothing billable   → the provisional entry is `cancelled` and no charge is owed.
 *
 * The `sms_billing_entries_live_campaign_unique` partial index guarantees the cancel-then-create
 * sequence can never leave two live charges behind, and makes a concurrent double-settlement
 * impossible.
 *
 * Servana moves NO money here (ADR-012): this writes liability rows only.
 */
final class PersonnelSmsBillingEntryFinalizer
{
    public function __construct(
        private readonly SmsCostCalculator $cost,
        private readonly SmsBillingEntryStateMachine $state,
    ) {}

    /** Must be called inside the settlement transaction. */
    public function settle(PersonnelSmsCampaign $campaign, int $billableRecipients): ?SmsBillingEntry
    {
        /** @var SmsBillingEntry|null $entry */
        $entry = SmsBillingEntry::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', SmsBillingEntryStatus::liveValues())
            ->lockForUpdate()
            ->first();

        if ($entry === null || $entry->status !== SmsBillingEntryStatus::Provisional) {
            return $entry; // nothing to settle, or already settled by a concurrent worker
        }

        $quantity = $this->cost->quantity($billableRecipients, $campaign->segment_count);

        if ($quantity === 0) {
            $this->state->ensure($entry->status, SmsBillingEntryStatus::Cancelled);
            $entry->forceFill(['status' => SmsBillingEntryStatus::Cancelled])->save();

            return $entry;
        }

        if ($quantity === $entry->quantity) {
            $this->state->ensure($entry->status, SmsBillingEntryStatus::Billable);
            $entry->forceFill(['status' => SmsBillingEntryStatus::Billable])->save();

            return $entry;
        }

        // The monetary columns are immutable, so a corrected quantity is a new row, not an edit.
        $this->state->ensure($entry->status, SmsBillingEntryStatus::Cancelled);
        $entry->forceFill(['status' => SmsBillingEntryStatus::Cancelled])->save();

        $unitCostMinor = $entry->unit_cost_minor;

        return SmsBillingEntry::query()->create([
            'merchant_id' => $campaign->merchant_id,
            'branch_id' => $campaign->branch_id,
            'campaign_id' => $campaign->id,
            'quantity' => $quantity,
            'unit_cost_minor' => $unitCostMinor,
            'amount_minor' => $quantity * $unitCostMinor,
            'currency' => $campaign->currency,
            'status' => SmsBillingEntryStatus::Billable,
            'billing_invoice_line_id' => null,
        ]);
    }

    /** Cancel the live entry for a campaign that will never send (cancellation path). */
    public function cancel(PersonnelSmsCampaign $campaign): ?SmsBillingEntry
    {
        /** @var SmsBillingEntry|null $entry */
        $entry = SmsBillingEntry::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', SmsBillingEntryStatus::liveValues())
            ->lockForUpdate()
            ->first();

        if ($entry === null || $entry->status->isTerminal()) {
            return $entry;
        }

        $this->state->ensure($entry->status, SmsBillingEntryStatus::Cancelled);
        $entry->forceFill(['status' => SmsBillingEntryStatus::Cancelled])->save();

        return $entry;
    }
}
