<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Submit a draft compensation plan for approval (Plan §59; Phase 20F, F8). Carries only the
 * mandatory change reason.
 *
 * `is_backdated` is NEVER accepted from the caller — the action computes it from the
 * Africa/Nairobi business date at submission. Submission records who is asking; it never approves,
 * activates, or supersedes.
 */
final class SubmitCompensationPlanRequest extends FormRequest
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
