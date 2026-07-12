<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Domain\Merchants\Enums\ServiceFeeTier;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validates first-time setup completion (Scope §3.2 steps 1–5).
 *
 * Route access (pending_setup + merchant_admin) is enforced by middleware; this
 * validates the payload shape. Cross-field rules ensure the two initial staff
 * emails are distinct from each other and from the owner — the Merchant
 * Administrator may add ONLY a Branch Manager and an HR user (Scope §3.2
 * "Add only ... Branch account user ... and ... Human Resource account user").
 */
final class CompleteFirstTimeSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_fee_tier' => ['required', 'string', Rule::in(array_column(ServiceFeeTier::cases(), 'value'))],

            // Phase 20B — the merchant selects an active plan + its effective price during
            // first-time setup (public ULIDs only; semantic checks — plan active, price belongs
            // to plan, price effective on the setup date — run in CompleteFirstTimeSetup).
            'subscription_plan_ulid' => ['required', 'string', 'size:26', 'exists:subscription_plans,ulid'],
            'subscription_plan_price_ulid' => ['required', 'string', 'size:26', 'exists:subscription_plan_prices,ulid'],

            'business_category' => ['required', 'string', 'min:2', 'max:80'],
            'contact_phone' => ['required', 'string', 'min:7', 'max:32'],
            'contact_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'receipt_display_name' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'town' => ['nullable', 'string', 'max:80'],
            'timezone' => ['nullable', 'string', 'max:64'],

            'branch' => ['required', 'array'],
            'branch.name' => ['required', 'string', 'min:2', 'max:160'],
            'branch.code' => ['required', 'string', 'min:2', 'max:20', 'alpha_num'],
            'branch.town' => ['nullable', 'string', 'max:80'],
            'branch.address' => ['nullable', 'string', 'max:255'],
            'branch.phone' => ['nullable', 'string', 'max:32'],
            'branch.email' => ['nullable', 'string', 'email:rfc', 'max:255'],

            'branch_manager_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'hr_email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['branch_manager_email', 'hr_email', 'contact_email'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $merge[$field] = Str::lower(trim($this->input($field)));
            }
        }

        if ($this->has('branch.email') && is_string($this->input('branch.email'))) {
            $branch = (array) $this->input('branch');
            $branch['email'] = Str::lower(trim((string) $this->input('branch.email')));
            $merge['branch'] = $branch;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $bm = Str::lower(trim((string) $this->input('branch_manager_email')));
            $hr = Str::lower(trim((string) $this->input('hr_email')));

            if ($bm !== '' && $bm === $hr) {
                $validator->errors()->add('hr_email', 'The Branch Manager and HR email addresses must be different.');
            }

            /** @var User|null $owner */
            $owner = $this->user();
            $ownerEmail = $owner !== null ? Str::lower(trim($owner->email)) : null;

            if ($ownerEmail !== null) {
                if ($bm === $ownerEmail) {
                    $validator->errors()->add('branch_manager_email', 'The Branch Manager email cannot be your own account email.');
                }
                if ($hr === $ownerEmail) {
                    $validator->errors()->add('hr_email', 'The HR email cannot be your own account email.');
                }
            }
        });
    }
}
