<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\PromotionalDiscount;
use App\Domain\Billing\Models\PromotionalDiscountTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Promotional-discount payload — the Super-Admin ADMIN view (Plan §53; Phase 20C). Exposes the ULID,
 * terms, scope, effective window, status, approval timestamp, sanitized reason, and the explicit
 * target rows (each by its own ULID + the referenced merchant/plan ULID or billing-mode value). Never
 * exposes internal ids or the created_by/approved_by user ids.
 *
 * @mixin PromotionalDiscount
 */
final class PromotionalDiscountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'type' => $this->type->value,
            'value' => $this->value,
            'currency' => $this->currency,
            'target_scope' => $this->target_scope->value,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'status' => $this->status->value,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'change_reason' => $this->change_reason,
            'targets' => $this->targets->map(static fn (PromotionalDiscountTarget $target): array => [
                'id' => $target->ulid,
                'target_type' => $target->target_type->value,
                'merchant_id' => $target->merchant?->ulid,
                'subscription_plan_id' => $target->plan?->ulid,
                'billing_mode' => $target->billing_mode?->value,
            ])->values()->all(),
        ];
    }
}
