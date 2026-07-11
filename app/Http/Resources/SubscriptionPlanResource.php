<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Subscription-plan payload (Plan §13.9, §47; ADR-011; Phase 20A). Exposes the plan ULID + NON-PRICE
 * metadata + status; prices and entitlements are included when eager-loaded. Never the internal id
 * (there is no price field on the plan — ADR-011).
 *
 * @mixin SubscriptionPlan
 */
final class SubscriptionPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'tier' => $this->tier,
            'metadata' => $this->metadata,
            'status' => $this->status->value,
            'sort_order' => $this->sort_order,
            'prices' => SubscriptionPlanPriceResource::collection($this->whenLoaded('prices')),
            'entitlements' => PlanEntitlementResource::collection($this->whenLoaded('entitlements')),
        ];
    }
}
