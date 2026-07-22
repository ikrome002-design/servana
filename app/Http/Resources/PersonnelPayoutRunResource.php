<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Compensation\Models\PersonnelPayoutRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 20H personnel-payout-run masked read (Plan §62). Exposes the run ULID, public branch reference,
 * period, single currency, status, and the signed integer gross total (clawback-heavy runs may net
 * negative — D-H9-1). The external payment reference is NEVER returned raw — only a boolean presence
 * flag; internal numeric ids, actor ids, and encrypted values never leave the server. Items are included
 * only when eager-loaded (detail view). Money is integer minor units (ADR-005).
 *
 * @mixin PersonnelPayoutRun
 */
final class PersonnelPayoutRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $threshold = $this->high_value_threshold_snapshot_minor;

        return [
            'id' => $this->ulid,
            'branch_id' => $this->branch === null ? null : $this->branch->ulid,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'currency' => $this->currency,
            'status' => $this->status->value,
            'gross_total_minor' => (int) $this->gross_total_minor,
            'high_value_threshold_snapshot_minor' => $threshold === null ? null : (int) $threshold,
            'is_high_value' => (bool) ($threshold !== null && $this->gross_total_minor > $threshold),
            'rejection_reason' => $this->rejection_reason,
            // Presence only — the encrypted external payment reference is never returned.
            'has_external_payment_reference' => (bool) ($this->external_payment_reference_encrypted !== null),
            // Explicit ternary (not ?->) so the generator publishes the genuinely-nullable type: a run is
            // unpaid until mark-paid, so paid_at is null for every non-paid run.
            'paid_at' => $this->paid_at === null ? null : $this->paid_at->toIso8601String(),
            'item_count' => $this->whenCounted('items'),
            'items' => PersonnelPayoutItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
