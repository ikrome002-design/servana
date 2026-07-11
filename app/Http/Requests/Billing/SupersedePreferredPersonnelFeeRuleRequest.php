<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Domain\Billing\Enums\PreferredFeeCalculationBasis;
use App\Domain\Billing\Enums\PreferredFeeCalculationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validate superseding an active preferred-personnel fee rule with new terms (Plan §13.10, §47;
 * Phase 20A). The scope/service is inherited from the superseded rule (not re-supplied). Same
 * value-shape rules as creation; `change_reason` required. Authorization + MFA + fresh step-up are
 * enforced by the route middleware.
 */
final class SupersedePreferredPersonnelFeeRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'calculation_type' => ['required', Rule::in(PreferredFeeCalculationType::values())],
            'fixed_amount_minor' => ['required_if:calculation_type,fixed_amount', 'prohibited_unless:calculation_type,fixed_amount', 'integer', 'min:0'],
            'currency' => ['required_if:calculation_type,fixed_amount', 'prohibited_unless:calculation_type,fixed_amount', 'string', 'size:3'],
            'percentage_basis_points' => ['required_if:calculation_type,percentage', 'prohibited_unless:calculation_type,percentage', 'integer', 'between:0,10000'],
            'calculation_basis' => ['required', Rule::in(PreferredFeeCalculationBasis::values())],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
            'change_reason' => ['required', 'string', 'min:2', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => Str::upper(trim($this->input('currency')))]);
        }
    }
}
