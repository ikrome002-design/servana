<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Domain\Billing\Enums\BillingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validate a platform billing-settings update (Plan §13.9, §47, §50; Phase 20A). Canonical
 * billing mode only; non-negative day counts; uppercase ISO currency; `settings` is a documented
 * JSON object. Authorization + MFA + fresh step-up are enforced by the route middleware.
 */
final class UpdatePlatformBillingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'billing_mode' => ['required', Rule::in(BillingMode::values())],
            'default_trial_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'currency' => ['required', 'string', 'size:3'],
            'settings' => ['sometimes', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => Str::upper(trim($this->input('currency')))]);
        }
    }
}
