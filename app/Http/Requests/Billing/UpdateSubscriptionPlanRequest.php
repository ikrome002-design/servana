<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a subscription-plan metadata update (Plan §13.9, §47; ADR-011; Phase 20A). Non-price
 * metadata only; the machine `key` and `status` are immutable here (retirement is a separate named
 * action). Authorization + MFA are enforced by the route middleware.
 */
final class UpdateSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'tier' => ['sometimes', 'nullable', 'string', 'max:60'],
            'metadata' => ['sometimes', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
