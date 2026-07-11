<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a plan-entitlement replacement (Plan §13.9, §20, §47; Phase 20A). The full desired
 * entitlement set is supplied; keys are unique within the payload; a null limit means unlimited
 * (when enabled). Managed under `platform.plan.manage`. Authorization + MFA are enforced by the
 * route middleware.
 */
final class UpdatePlanEntitlementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entitlements' => ['present', 'array'],
            'entitlements.*.entitlement_key' => ['required', 'string', 'min:2', 'max:120', 'distinct'],
            'entitlements.*.enabled' => ['required', 'boolean'],
            'entitlements.*.limit_int' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
