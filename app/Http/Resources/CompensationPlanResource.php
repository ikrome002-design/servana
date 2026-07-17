<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Compensation\Enums\CompensationPlanStatus;
use App\Domain\Compensation\Models\PersonnelCompensationPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Personnel compensation plan (Plan §59; Scope §12.2-§12.9; Phase 20F). ULIDs only — never an
 * internal id, never personnel contact data (the subject is exposed as its staff-profile ULID +
 * display name only, and Merchant Personnel contact export does not exist anywhere: guardrail §6.8).
 *
 * Salary is integer minor units (never float) and no EARNED amount exists to expose: Phase 20F is
 * configuration only. `capabilities` mirrors the state machine so the SPA can gate affordances —
 * the backend policy/route remains the security boundary.
 *
 * @mixin PersonnelCompensationPlan
 */
final class CompensationPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'staff_profile_id' => $this->whenLoaded('staffProfile', fn (): ?string => $this->staffProfile?->ulid),
            'staff_display_name' => $this->whenLoaded('staffProfile', fn (): ?string => $this->staffProfile?->display_name),
            'branch_id' => $this->whenLoaded('branch', fn (): ?string => $this->branch?->ulid),
            'compensation_model' => $this->compensation_model->value,
            'compensation_model_label' => $this->compensation_model->label(),
            // Salary terms are NULL for commission_only (DB model-shape CHECK).
            'salary_amount_minor' => $this->salary_amount_minor,
            'salary_currency' => $this->salary_currency,
            // Null for commission_only, and null for every transition timestamp the plan has not
            // reached yet. Each null branch is spelled out rather than using `?->`: the OpenAPI
            // generator infers nullability from an explicit null ternary but not through the
            // nullsafe operator, and a contract that promised non-nullable strings here would lie
            // to the generated SPA types.
            'salary_period' => $this->salary_period === null ? null : $this->salary_period->value,
            'salary_payout_day' => $this->salary_payout_day,
            // NULL for salary_only — Plan §80's named invariant, guaranteed by the DB.
            'commission_rule' => CommissionRuleResource::make($this->whenLoaded('commissionRule')),
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to === null ? null : $this->effective_to->toDateString(),
            'is_backdated' => $this->is_backdated,
            // supersedes_plan_id is a nullable FK, so a loaded relation can still be null.
            'supersedes_plan_id' => $this->whenLoaded('supersedesPlan', fn (): ?string => $this->supersedesPlan === null ? null : $this->supersedesPlan->ulid),
            'notes' => $this->notes,
            'change_reason' => $this->change_reason,
            'submitted_at' => $this->submitted_at === null ? null : $this->submitted_at->toIso8601String(),
            'approved_at' => $this->approved_at === null ? null : $this->approved_at->toIso8601String(),
            'rejected_at' => $this->rejected_at === null ? null : $this->rejected_at->toIso8601String(),
            'created_at' => $this->created_at === null ? null : $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at === null ? null : $this->updated_at->toIso8601String(),
            'capabilities' => [
                // UX affordances only — the server re-authorizes every mutation.
                'can_update_draft' => $this->status->isEditable(),
                'can_submit' => $this->status->isEditable(),
                'can_approve' => $this->status === CompensationPlanStatus::PendingApproval,
                'can_reject' => $this->status === CompensationPlanStatus::PendingApproval,
                'can_cancel' => in_array($this->status, [
                    CompensationPlanStatus::Draft,
                    CompensationPlanStatus::Scheduled,
                ], true),
                'is_terminal' => $this->status->isTerminal(),
            ],
        ];
    }
}
