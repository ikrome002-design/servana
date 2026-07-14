<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\PlatformFeeConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Percentage platform-fee configuration payload — the Super-Admin admin view (Plan §51, §52; Phase 20E).
 * Exposes the ULID, billing-mode applicability, basis points, fixed component, tier behaviour, shared
 * split, fee basis, currency, effective window, status, approval timestamp, sanitized reason, and the
 * capability flags. NEVER exposes internal ids or the created_by/approved_by user ids.
 *
 * @mixin PlatformFeeConfiguration
 */
final class PlatformFeeConfigurationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'billing_mode' => $this->billing_mode->value,
            'percentage_basis_points' => $this->percentage_basis_points,
            'fixed_component_minor' => $this->fixed_component_minor,
            'tier_behavior' => $this->tier_behavior?->value,
            'shared_split_basis_points' => $this->shared_split_basis_points,
            'fee_basis_type' => $this->fee_basis_type?->value,
            'currency' => $this->currency,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'status' => $this->status->value,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'change_reason' => $this->change_reason,
            'capabilities' => [
                'editable' => $this->status->value === 'draft',
                'approvable' => in_array($this->status->value, ['draft', 'scheduled'], true),
                'supersedable' => $this->status->value === 'active',
                'cancellable' => in_array($this->status->value, ['draft', 'scheduled'], true),
            ],
        ];
    }
}
