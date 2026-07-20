<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Compensation\Models\CommissionRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

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
            // §9.1 selected-services membership. Canonical contract = the public ULIDs; `selected_services`
            // adds the safe display name so the HR form can hydrate without a service-list endpoint (HR has
            // no `service.view`). Always an array: [] for all_services/service_category. Deterministic order
            // (name then ULID) comes from the relationship. Never internal ids.
            'selected_service_ulids' => $this->selectedServiceModels()->map(fn (Service $s): string => $s->ulid)->values()->all(),
            'selected_services' => $this->selectedServiceModels()
                ->map(fn (Service $s): array => ['ulid' => $s->ulid, 'name' => $s->name])
                ->values()->all(),
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

    /**
     * The loaded selected services, or an empty collection when the relation is not loaded — never a lazy
     * load (controllers eager-load `selectedServices`), so the masked output is always a stable array and
     * never triggers an N+1.
     *
     * @return Collection<int, Service>
     */
    private function selectedServiceModels(): Collection
    {
        /** @var Collection<int, Service> $services */
        $services = $this->relationLoaded('selectedServices') ? $this->selectedServices : new Collection;

        return $services;
    }
}
