<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate subscription-plan creation — NON-PRICE metadata only (Plan §13.9, §47; ADR-011;
 * Phase 20A). No price/amount field is accepted (price lives solely in subscription_plan_prices).
 * `key` is a stable, unique machine key. Authorization + MFA are enforced by the route middleware.
 */
final class StoreSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('subscription_plans', 'key')],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'tier' => ['nullable', 'string', 'max:60'],
            'metadata' => ['sometimes', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
