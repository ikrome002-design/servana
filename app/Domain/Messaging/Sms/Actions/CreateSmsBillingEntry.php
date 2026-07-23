<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Actions;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Messaging\Sms\Enums\SmsBillingEntryStatus;
use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use App\Domain\Messaging\Sms\Models\SmsBillingEntry;
use App\Domain\Messaging\Sms\Support\SmsCostCalculator;
use App\Models\User;

/**
 * Create the single PROVISIONAL billable-SMS entry for a campaign (Plan §64 "roll up billable SMS
 * charge to Servana billing"; ADR-005; Phase 21S).
 *
 * IDEMPOTENT BY CONSTRUCTION. It first looks for an existing LIVE entry
 * (`provisional`/`billable`/`invoiced`) and returns it unchanged; the
 * `sms_billing_entries_live_campaign_unique` partial index is the database backstop, so even a
 * concurrent duplicate confirm or a job retry cannot create a second charge.
 *
 * SERVANA MOVES NO MONEY (ADR-012). This writes a liability row only: no Wallet payment resource,
 * no payment attempt, no subscription payment event, no provider call. `billing_invoice_line_id`
 * stays null until a future billing phase rolls SMS charges into a subscription invoice line —
 * Phase 21S owns the queue, never the invoicing.
 *
 * Must be called INSIDE the confirm transaction, so a campaign can never be committed without its
 * charge.
 */
final class CreateSmsBillingEntry
{
    public function __construct(
        private readonly SmsCostCalculator $cost,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PersonnelSmsCampaign $campaign, User $actor): SmsBillingEntry
    {
        $existing = SmsBillingEntry::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', SmsBillingEntryStatus::liveValues())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $quantity = $this->cost->quantity($campaign->recipient_count, $campaign->segment_count);
        $unitCostMinor = $this->cost->unitCostMinor();

        $entry = SmsBillingEntry::query()->create([
            'merchant_id' => $campaign->merchant_id,
            'branch_id' => $campaign->branch_id,
            'campaign_id' => $campaign->id,
            'quantity' => $quantity,
            'unit_cost_minor' => $unitCostMinor,
            // Never independently supplied — the DB CHECK re-verifies the product.
            'amount_minor' => $quantity * $unitCostMinor,
            'currency' => $campaign->currency,
            'status' => SmsBillingEntryStatus::Provisional,
            'billing_invoice_line_id' => null,
        ]);

        $this->audit->record(
            AuditEvent::PersonnelSmsBillingEntryCreated,
            $actor,
            $campaign->merchant_id,
            $campaign->branch_id,
            $entry,
            [
                'campaign_ulid' => $campaign->ulid,
                'billing_entry_ulid' => $entry->ulid,
                'quantity' => $quantity,
                'unit_cost_minor' => $unitCostMinor,
                'amount_minor' => $entry->amount_minor,
                'currency' => $entry->currency,
                'status' => $entry->status->value,
            ],
        );

        return $entry;
    }
}
