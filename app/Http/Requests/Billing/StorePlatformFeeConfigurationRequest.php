<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\CanonicalPlatformFeeTier;
use App\Domain\Billing\Enums\PlatformFeeBasisType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Percentage platform-fee configuration payload (Plan §51, §52; Phase 20E, Increment 6). Used by
 * create / update-draft / supersede. Authorization + MFA + fresh step-up are enforced by the route
 * middleware; the controller authorizes via the policy. The request NEVER accepts an authoritative
 * `status`, `created_by`, `approved_by`, `approved_at`, or any server-owned field — those are set only
 * by the domain actions. Value-shape coherence is enforced here at the surface and by the DB CHECKs
 * (the authoritative guard; the resolved-tier gate for `validated_paid_amount` is DB-enforced).
 */
final class StorePlatformFeeConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'billing_mode' => ['required', 'string', Rule::in(BillingMode::values())],
            'percentage_basis_points' => ['nullable', 'integer', 'between:0,10000'],
            'fixed_component_minor' => ['nullable', 'integer', 'min:0'],
            'tier_behavior' => ['nullable', 'string', Rule::in(CanonicalPlatformFeeTier::values())],
            'shared_split_basis_points' => ['nullable', 'integer', 'between:0,10000', 'required_if:tier_behavior,shared'],
            'fee_basis_type' => ['nullable', 'string', Rule::in(PlatformFeeBasisType::values())],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'change_reason' => ['required', 'string', 'min:2', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
        if (is_string($this->input('change_reason'))) {
            $this->merge(['change_reason' => trim($this->input('change_reason'))]);
        }
    }
}
