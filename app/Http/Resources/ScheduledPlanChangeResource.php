<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\ScheduledPlanChange;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Billing\Models\SubscriptionPlanPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Scheduled no-proration next-cycle plan change (Plan §48; Phase 20B). Exposes public ULIDs +
 * the server-computed effective boundary date; never internal ids or the creating user id.
 *
 * @mixin ScheduledPlanChange
 */
final class ScheduledPlanChangeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SubscriptionPlan $plan */
        $plan = $this->targetPlan;
        /** @var SubscriptionPlanPrice $price */
        $price = $this->targetPrice;

        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'effective_at' => $this->effective_at->toDateString(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'target_plan' => [
                'id' => $plan->ulid,
                'key' => $plan->key,
                'name' => $plan->name,
                'tier' => $plan->tier,
            ],
            'target_price' => [
                'id' => $price->ulid,
                'amount_minor' => $price->amount_minor,
                'currency' => $price->currency,
                'billing_interval' => $price->billing_interval->value,
            ],
        ];
    }
}
