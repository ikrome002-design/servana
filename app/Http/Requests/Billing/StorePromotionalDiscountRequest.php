<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Domain\Billing\Enums\PromotionalDiscountType;
use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Http\Requests\Billing\Concerns\ValidatesOfferTargets;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validate promotional-discount draft creation (Plan §53; Gate C2/C5; Phase 20C). Mirrors the DB
 * value/currency + scope↔target CHECKs at the API boundary: percentage ⇒ basis points 1..10000, no
 * currency; fixed_amount ⇒ positive minor units + uppercase 3-char currency. Targets are explicit rows
 * referencing merchants/plans by ULID. The request NEVER accepts status/approved_by/approved_at.
 * Authorization + MFA + fresh step-up are enforced by the route middleware.
 */
class StorePromotionalDiscountRequest extends FormRequest
{
    use ValidatesOfferTargets;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'type' => ['required', Rule::in(PromotionalDiscountType::values())],
            'value' => ['required', 'integer', 'min:1'],
            'currency' => ['required_if:type,fixed_amount', 'prohibited_unless:type,fixed_amount', 'string', 'size:3'],
            'target_scope' => ['required', Rule::in(PromotionTargetScope::values())],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
        ], $this->targetRules());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Percentage cannot exceed 100% (10000 basis points).
            if ($this->input('type') === PromotionalDiscountType::Percentage->value
                && is_numeric($this->input('value')) && (int) $this->input('value') > 10000) {
                $validator->errors()->add('value', 'A percentage discount cannot exceed 100% (10000 basis points).');
            }
            $this->validateTargetCoherence($validator);
        });
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => Str::upper(trim($this->input('currency')))]);
        }
    }
}
