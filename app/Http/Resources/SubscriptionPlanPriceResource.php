<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\SubscriptionPlanPrice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan-price payload (Plan §13.9, §47; ADR-011; Phase 20A). Exposes the price ULID, integer minor
 * amount, currency, interval, and effective range + a derived lifecycle label (future/current/
 * historical). Never the internal id or `created_by`.
 *
 * @mixin SubscriptionPlanPrice
 */
final class SubscriptionPlanPriceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $today = CarbonImmutable::now('Africa/Nairobi')->startOfDay();
        $lifecycle = $this->effective_from->isAfter($today)
            ? 'future'
            : (($this->effective_to !== null && ! $this->effective_to->isAfter($today)) ? 'historical' : 'current');

        return [
            'id' => $this->ulid,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'billing_interval' => $this->billing_interval->value,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to === null ? null : $this->effective_to->toDateString(),
            'lifecycle' => $lifecycle,
        ];
    }
}
