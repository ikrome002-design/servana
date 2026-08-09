<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a scheduled SMS pricing rule (COR-UI08-001 §9; Phase UI-08).
 *
 * Integer minor units only (ADR-005) — a decimal price is rejected here rather than silently
 * truncated. Basis points are integers in 0…10000. The reason is MANDATORY: a pricing change
 * without a stated reason is not auditable, and the column is NOT NULL.
 *
 * `effective_from` must be now or later. Backdating could not rewrite a charge (sms_billing_entries
 * is trigger-frozen) but it would make the recorded pricing history untruthful. The action
 * re-checks it inside the transaction, so this rule is convenience, not the boundary.
 *
 * Authorization, MFA, fresh step-up and idempotency are enforced by the route middleware.
 */
final class ScheduleSmsBillingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'unit_cost_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
            'effective_from' => ['required', 'date', 'after_or_equal:now'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
            'tax_basis_points' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'usage_warning_threshold_units' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'usage_anomaly_threshold_basis_points' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'unit_cost_minor.integer' => 'The unit cost must be an integer number of minor units (cents), never a decimal amount.',
            'effective_from.after_or_equal' => 'An SMS billing rule cannot be scheduled in the past; the pricing series is append-only.',
            'reason.required' => 'A reason is required so the pricing change is auditable.',
        ];
    }
}
