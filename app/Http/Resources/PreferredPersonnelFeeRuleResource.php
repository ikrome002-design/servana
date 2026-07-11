<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use App\Domain\Platform\Services\PlatformServiceLocator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Preferred-personnel fee-rule payload — the Super-Admin ADMIN view (Plan §13.10, §47; Phase 20A).
 * Exposes the rule ULID, terms, scope, effective range, status, approval timestamp, and change
 * reason. Never exposes the internal id or the `created_by`/`approved_by` user ids; the service is
 * shown by its ULID only.
 *
 * @mixin PreferredPersonnelFeeRule
 */
final class PreferredPersonnelFeeRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'calculation_type' => $this->calculation_type->value,
            'fixed_amount_minor' => $this->fixed_amount_minor,
            'percentage_basis_points' => $this->percentage_basis_points,
            'currency' => $this->currency,
            'calculation_basis' => $this->calculation_basis->value,
            'scope' => $this->scope->value,
            'service_id' => $this->service_id === null
                ? null
                : app(PlatformServiceLocator::class)->ulidForId($this->service_id),
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to === null ? null : $this->effective_to->toDateString(),
            'status' => $this->status->value,
            'approved_at' => $this->approved_at === null ? null : $this->approved_at->toIso8601String(),
            'change_reason' => $this->change_reason,
        ];
    }
}
