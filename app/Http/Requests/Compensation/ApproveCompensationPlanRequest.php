<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve a pending compensation plan (Plan §59; Phase 20F, F8).
 *
 * `acknowledge_impact_preview` is the approver's explicit acknowledgement that they were shown the
 * deterministic impact preview. It is REQUIRED for a backdated plan: the controller builds the
 * preview server-side and passes it to the action, which fails closed
 * (`backdated_approval_requires_impact_preview`) without it. The preview itself is never accepted
 * from the client — a caller could otherwise "approve" against a preview that never existed.
 *
 * The target state (`active` vs `scheduled`), `approved_by`, `approved_at`, and the supersede of any
 * incumbent are all server-owned. Fresh step-up is enforced by RequireFreshMfa on the route, and
 * maker/checker (approver ≠ submitter) by the action + a DB CHECK.
 */
final class ApproveCompensationPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'change_reason' => ['required', 'string', 'min:2', 'max:2000'],
            'acknowledge_impact_preview' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('change_reason'))) {
            $this->merge(['change_reason' => trim($this->input('change_reason'))]);
        }
    }
}
