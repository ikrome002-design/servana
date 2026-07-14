<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Resolve a percentage platform-fee dispute (Plan §13.10 [Correction 3], §953; Phase 20E, Increment 6).
 * A mandatory resolution note is required; an OPTIONAL server-validated integer `money_change_amount_minor`
 * (non-zero when present) drives an additive `platform_fee_adjustments` row — it never edits the ledger
 * amount. Authorization + fresh step-up + period-lock + maker/checker are enforced by the route
 * middleware + the action. The request NEVER accepts `status`, `resolved_by`, or a calculated amount for
 * the ledger.
 */
final class ResolvePlatformFeeDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'min:2', 'max:2000'],
            'money_change_amount_minor' => ['nullable', 'integer', 'not_in:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('resolution_note'))) {
            $this->merge(['resolution_note' => trim($this->input('resolution_note'))]);
        }
    }
}
