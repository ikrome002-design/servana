<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Compensation\Models\CommissionRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commission-rule configuration (Plan §59; Scope §12.7, §18.3; Phase 20F). ULIDs only — never an
 * internal id, never a `created_by`/`approved_by` numeric key. Rates are integer basis points and
 * fixed amounts integer minor units (never float, never a computed commission amount: Phase 20F
 * computes no money).
 *
 * @mixin CommissionRule
 */
final class CommissionRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'calculation_type' => $this->calculation_type->value,
            'calculation_basis' => $this->calculation_basis->value,
            'applies_to' => $this->applies_to->value,
            // service_category_id is a nullable FK, so a loaded relation can still be null.
            'service_category_id' => $this->whenLoaded('serviceCategory', fn (): ?string => $this->serviceCategory === null ? null : $this->serviceCategory->ulid),
            // Exactly one calculation value is ever populated (DB value-shape CHECK).
            'percentage_basis_points' => $this->percentage_basis_points,
            'fixed_amount_minor' => $this->fixed_amount_minor,
            'currency' => $this->currency,
            // F6 — a basis-INCLUSION flag consumed by Phase 20G; never a rate modifier here.
            'applies_to_preferred_personnel_fee' => $this->applies_to_preferred_personnel_fee,
            'effective_from' => $this->effective_from->toDateString(),
            // An open-ended rule and an unapproved rule genuinely emit null. Spell the null branch
            // out rather than using `?->`: the OpenAPI generator infers nullability from an explicit
            // null ternary but not through the nullsafe operator, and a contract that promised a
            // non-nullable string here would lie to the generated SPA types.
            'effective_to' => $this->effective_to === null ? null : $this->effective_to->toDateString(),
            'notes' => $this->notes,
            'change_reason' => $this->change_reason,
            'is_editable' => $this->status->isEditable(),
            'created_at' => $this->created_at === null ? null : $this->created_at->toIso8601String(),
            'approved_at' => $this->approved_at === null ? null : $this->approved_at->toIso8601String(),
        ];
    }
}
