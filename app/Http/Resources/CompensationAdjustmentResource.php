<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Compensation\Models\CompensationAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 20G compensation-adjustment masked read (Plan §60/§61). Exposes the adjustment ULID, family,
 * signed integer amount + currency, reason, the public staff/branch references, and the created
 * timestamp. NEVER exposes internal ids, actor ids, source ledger ids, payout linkage, or private
 * contact data.
 *
 * @mixin CompensationAdjustment
 */
final class CompensationAdjustmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'adjustment_type' => $this->adjustment_type->value,
            'amount_minor' => (int) $this->amount_minor,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'staff_profile_id' => $this->staffProfile === null ? null : $this->staffProfile->ulid,
            'staff_display_name' => $this->staffProfile === null ? null : $this->staffProfile->display_name,
            'branch_id' => $this->branch === null ? null : $this->branch->ulid,
            // Cast to a real boolean so the OpenAPI generator publishes `boolean` (a bare `A || B`
            // expression was mis-inferred as `string`); the runtime JSON value is unchanged (true/false).
            'has_source' => (bool) ($this->source_commission_ledger_id !== null || $this->source_salary_ledger_id !== null),
            'created_at' => $this->created_at === null ? null : $this->created_at->toIso8601String(),
        ];
    }
}
