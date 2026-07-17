<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reject a pending compensation plan (Plan §59; Phase 20F). A reason is mandatory — a rejection is
 * a governance decision that must be explainable. `rejected_by`/`rejected_at` are server-owned.
 *
 * Rejection never touches the incumbent active plan: the personnel keeps earning as before.
 */
final class RejectCompensationPlanRequest extends FormRequest
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
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('change_reason'))) {
            $this->merge(['change_reason' => trim($this->input('change_reason'))]);
        }
    }
}
