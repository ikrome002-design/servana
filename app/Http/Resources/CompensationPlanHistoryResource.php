<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Compensation\Models\CompensationPlanHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Append-only compensation change history (Plan §59, §80; Phase 20F). ULIDs only. `changed_fields`
 * is the masked configuration diff / impact-preview summary the writer stored — it already carries
 * public ULIDs and configured terms only, never contact data, an internal id, or money that was
 * earned (history records CONFIGURATION changes; it is not a ledger).
 *
 * @mixin CompensationPlanHistory
 */
final class CompensationPlanHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'event' => $this->event->value,
            'event_label' => $this->event->label(),
            // The `created` event has no prior status. Spelled out rather than `?->` so the OpenAPI
            // generator publishes the nullability (it infers it from an explicit null ternary only).
            'from_status' => $this->from_status === null ? null : $this->from_status->value,
            'to_status' => $this->to_status->value,
            'changed_fields' => $this->changed_fields,
            'was_backdated' => $this->was_backdated,
            'change_reason' => $this->change_reason,
            'effective_from' => $this->effective_from->toDateString(),
            'actor_display_name' => $this->whenLoaded('actor', fn (): ?string => $this->actor?->name),
            'occurred_at' => $this->created_at === null ? null : $this->created_at->toIso8601String(),
        ];
    }
}
