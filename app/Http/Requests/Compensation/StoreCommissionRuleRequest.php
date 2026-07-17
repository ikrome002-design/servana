<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use App\Domain\Compensation\Enums\CommissionAppliesTo;
use App\Domain\Compensation\Enums\CommissionCalculationBasis;
use App\Domain\Compensation\Enums\CommissionCalculationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create a DRAFT commission rule (Plan §59; Scope §12.7 Step 3A, §18.3; Phase 20F).
 *
 * F4 value shape: percentage ⇒ basis points only; fixed ⇒ minor units + currency only. Integer
 * basis points and integer minor units — never float. The structural ceiling is 0..10000 bp
 * (0-100%); the Scope's "configured merchant/platform maximum" has no settings substrate anywhere
 * in the Plan/repository, so no cap surface is invented (see docs/proof/phase-20f.md §F4).
 *
 * **Server-owned fields are never accepted**: merchant_id, branch_id, status, created_by,
 * approved_by/approved_at, ulid, id, timestamps.
 */
class StoreCommissionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'calculation_type' => ['required', Rule::enum(CommissionCalculationType::class)],
            'calculation_basis' => ['required', Rule::enum(CommissionCalculationBasis::class)],
            'applies_to' => ['required', Rule::enum(CommissionAppliesTo::class)],
            'service_category_id' => ['nullable', 'string', 'size:26'],
            // Integer basis points only — `integer` rejects "10.5" and 10.5.
            'percentage_basis_points' => ['nullable', 'integer', 'between:0,10000'],
            'fixed_amount_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3', 'uppercase', 'alpha'],
            'applies_to_preferred_personnel_fee' => ['nullable', 'boolean'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
            'change_reason' => ['required', 'string', 'min:2', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** F4 value shape + applies-to coherence at the request boundary (DB CHECK stays authoritative). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = CommissionCalculationType::tryFrom((string) $this->input('calculation_type'));
            $appliesTo = CommissionAppliesTo::tryFrom((string) $this->input('applies_to'));

            if ($type === CommissionCalculationType::Percentage) {
                if (! $this->filled('percentage_basis_points')) {
                    $validator->errors()->add('percentage_basis_points', 'A percentage commission rule requires a rate in basis points.');
                }
                if ($this->filled('fixed_amount_minor') || $this->filled('currency')) {
                    $validator->errors()->add('fixed_amount_minor', 'A percentage commission rule cannot carry a fixed amount or currency.');
                }
            }

            if ($type === CommissionCalculationType::FixedAmount) {
                if (! $this->has('fixed_amount_minor') || $this->input('fixed_amount_minor') === null) {
                    $validator->errors()->add('fixed_amount_minor', 'A fixed commission rule requires an amount.');
                }
                if (! $this->filled('currency')) {
                    $validator->errors()->add('currency', 'A fixed commission rule requires a currency.');
                }
                if ($this->filled('percentage_basis_points')) {
                    $validator->errors()->add('percentage_basis_points', 'A fixed commission rule cannot carry a percentage rate.');
                }
            }

            if ($appliesTo === CommissionAppliesTo::ServiceCategory && ! $this->filled('service_category_id')) {
                $validator->errors()->add('service_category_id', 'A category-scoped commission rule requires a service category.');
            }

            if ($appliesTo !== null && ! $appliesTo->requiresServiceCategory() && $this->filled('service_category_id')) {
                $validator->errors()->add('service_category_id', 'This commission rule applicability cannot carry a service category.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('change_reason'))) {
            $this->merge(['change_reason' => trim($this->input('change_reason'))]);
        }
    }
}
