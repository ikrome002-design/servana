<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Personnel SMS campaign as its author sees it (Plan §64; ADR-010; Phase 21S).
 *
 * Carries the public ULID, the lifecycle status, the composition metrics, the money and the
 * timestamps. Deliberately ABSENT:
 *   - the message body (encrypted at rest and `$hidden`; a personnel-authored message may name a
 *     client, so it is never returned in a list or detail payload);
 *   - every recipient contact — the campaign holds none, and the recipients endpoint returns only
 *     the masked form;
 *   - internal numeric ids, the merchant/branch ids, and the staff profile id.
 *
 * Money uses the Plan §11.4 shape (`{amount, currency, formatted}`), computed server-side.
 *
 * @mixin PersonnelSmsCampaign
 */
final class PersonnelSmsCampaignResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'recipient_count' => $this->recipient_count,
            'message_character_count' => $this->message_character_count,
            'segment_count' => $this->segment_count,
            'estimated_cost' => $this->estimatedCost()->toArray(),
            'final_cost' => $this->finalCost()?->toArray(),
            'failure_reason_code' => $this->failure_reason_code,
            'is_cancellable' => $this->status->isCancellable(),
            'consent_snapshot_at' => $this->consent_snapshot_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
