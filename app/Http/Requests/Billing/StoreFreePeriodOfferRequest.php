<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Domain\Billing\Enums\PromotionTargetScope;
use App\Http\Requests\Billing\Concerns\ValidatesOfferTargets;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate free-period-offer draft creation (Plan §53; Gate C2; Phase 20C). `free_period_days` is
 * 1..365; targets are explicit rows referencing merchants/plans by ULID, coherent with the scope. The
 * request NEVER accepts status/approved_by/approved_at. Authorization + MFA + fresh step-up are
 * enforced by the route middleware.
 */
class StoreFreePeriodOfferRequest extends FormRequest
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
            'free_period_days' => ['required', 'integer', 'between:1,365'],
            'target_scope' => ['required', Rule::in(PromotionTargetScope::values())],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
        ], $this->targetRules());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateTargetCoherence($validator);
        });
    }
}
