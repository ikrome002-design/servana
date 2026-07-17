<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\FreePeriodOffer;
use App\Domain\Billing\Models\FreePeriodOfferTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Free-period-offer payload — the Super-Admin ADMIN view (Plan §53; Phase 20C). Exposes the ULID,
 * days, scope, effective window, status, approval timestamp, sanitized reason, and the explicit target
 * rows (each by its own ULID + the referenced merchant/plan ULID or billing-mode value). Never exposes
 * internal ids or the created_by/approved_by user ids.
 *
 * @mixin FreePeriodOffer
 */
final class FreePeriodOfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'free_period_days' => $this->free_period_days,
            'target_scope' => $this->target_scope->value,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to === null ? null : $this->effective_to->toDateString(),
            'status' => $this->status->value,
            'approved_at' => $this->approved_at === null ? null : $this->approved_at->toIso8601String(),
            'change_reason' => $this->change_reason,
            'targets' => $this->targets->map(static fn (FreePeriodOfferTarget $target): array => [
                'id' => $target->ulid,
                'target_type' => $target->target_type->value,
                // A target names exactly one of merchant / plan / billing_mode, so the other
                // two are genuinely null. Each null branch is spelled out because the OpenAPI
                // generator infers nullability from an explicit null ternary but not through
                // the nullsafe operator.
                'merchant_id' => $target->merchant === null ? null : $target->merchant->ulid,
                'subscription_plan_id' => $target->plan === null ? null : $target->plan->ulid,
                'billing_mode' => $target->billing_mode === null ? null : $target->billing_mode->value,
            ])->values()->all(),
        ];
    }
}
