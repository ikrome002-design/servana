<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Messaging\Sms\ValueObjects\SmsCampaignPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The advisory SMS preview payload (Plan §64; ADR-010; Phase 21S).
 *
 * COUNTS, CODES AND MONEY ONLY. `excluded_reasons` is a reason-code → count map, never a per-client
 * list — that is what stops the preview endpoint from confirming which guessed ULIDs exist, and it
 * is why the response can be shown safely without exposing a single contact.
 *
 * Money follows the Plan §11.4 shape (`{amount, currency, formatted}`) from the Money value object,
 * so the frontend renders a server-computed figure and never derives one.
 *
 * @mixin SmsCampaignPreview
 */
final class SmsCampaignPreviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SmsCampaignPreview $preview */
        $preview = $this->resource;

        return [
            'recipient_count' => $preview->recipientCount,
            'excluded_count' => $preview->excludedCount,
            // Object cast keeps an empty map as `{}` rather than `[]` in JSON.
            'excluded_reasons' => (object) $preview->exclusionCounts,
            'message_character_count' => $preview->characterCount,
            'segment_count' => $preview->segmentCount,
            'requires_unicode' => $preview->requiresUnicode,
            'characters_remaining_in_segment' => $preview->charactersRemainingInSegment,
            'estimated_cost' => $preview->estimatedCost->toArray(),
            'unit_cost_minor' => $preview->unitCostMinor,
            'max_recipients' => $preview->maxRecipients,
            'max_message_characters' => $preview->maxMessageCharacters,
            // The §64 "billing notice": a plain statement that confirming owes money. It is a
            // notice, not a charge — nothing is billed until confirmation.
            'billing_notice' => 'Sending this campaign adds an SMS charge to your Servana billing.',
        ];
    }
}
