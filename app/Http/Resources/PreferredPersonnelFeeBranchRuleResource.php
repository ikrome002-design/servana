<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\PreferredPersonnelFeeRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Preferred-personnel fee-rule payload for the branch read (Plan §13.10, §19.3; Phase 20A). The
 * Branch Manager sees ONLY the applicable EFFECTIVE rule's terms — never draft/scheduled
 * administration metadata, the status, approval internals, change reason, service/user ids, or the
 * internal/ULID id. Read-only.
 *
 * @mixin PreferredPersonnelFeeRule
 */
final class PreferredPersonnelFeeBranchRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'calculation_type' => $this->calculation_type->value,
            'fixed_amount_minor' => $this->fixed_amount_minor,
            'percentage_basis_points' => $this->percentage_basis_points,
            'currency' => $this->currency,
            'calculation_basis' => $this->calculation_basis->value,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to === null ? null : $this->effective_to->toDateString(),
        ];
    }
}
