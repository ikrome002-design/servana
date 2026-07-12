<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a platform merchant-governance mutation (suspend / reactivate / deactivate) (Plan §22,
 * §24.1; Phase 20B). A governance reason is MANDATORY for every operational-status change — it is
 * recorded in the redacted audit context. Authorization + MFA + fresh step-up are enforced by route
 * middleware. Shared by all three governance routes (identical contract).
 */
final class MerchantGovernanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
