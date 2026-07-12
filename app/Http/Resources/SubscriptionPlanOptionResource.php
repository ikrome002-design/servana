<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An available plan option with its currently-effective price for the merchant's interval + currency
 * (Plan §47, §48; Phase 20B). The effective price is resolved server-side and attached as
 * `effective_price` (null when no effective price exists for that interval/currency). Read-only;
 * public ULIDs only.
 *
 * @mixin SubscriptionPlan
 */
final class SubscriptionPlanOptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SubscriptionPlanPrice|null $price */
        $price = $this->resource->effective_price ?? null;

        return [
            'id' => $this->ulid,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'tier' => $this->tier,
            'is_current' => (bool) ($this->resource->is_current ?? false),
            'effective_price' => $price === null ? null : [
                'id' => $price->ulid,
                'amount_minor' => $price->amount_minor,
                'currency' => $price->currency,
                'billing_interval' => $price->billing_interval->value,
            ],
        ];
    }
}
