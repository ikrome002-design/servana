<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Domain\Billing\Enums\BillingInterval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validate plan-price creation (Plan §13.9, §47; ADR-011; Phase 20A). Integer minor units,
 * uppercase ISO currency, canonical billing interval, effective range ordering. The DB
 * `EXCLUDE` constraint remains the authoritative overlap guard (→ 409). A future `effective_from`
 * is a scheduled price. Authorization + MFA + fresh step-up are enforced by the route middleware.
 */
final class StorePlanPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'billing_interval' => ['required', Rule::in(BillingInterval::values())],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => Str::upper(trim($this->input('currency')))]);
        }
    }
}
