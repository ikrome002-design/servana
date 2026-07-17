<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancel a draft or scheduled compensation plan before it ever takes effect (Plan §59; Phase 20F).
 * A reason is mandatory.
 *
 * An ACTIVE plan is never cancelled — it is SUPERSEDED by approving a successor (the state machine
 * rejects `active → cancelled` with `422 invalid_state_transition`).
 */
final class CancelCompensationPlanRequest extends FormRequest
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
