<?php

declare(strict_types=1);

namespace App\Http\Requests\Branches;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validate branch creation (Scope §3.3). Code is unique per merchant (admin-only
 * authority is enforced in the controller).
 */
final class CreateBranchRequest extends FormRequest
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
        $merchantId = app(TenantContext::class)->merchantId();

        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'code' => [
                'required', 'string', 'min:2', 'max:20', 'alpha_dash',
                Rule::unique('merchant_branches', 'code')->where('merchant_id', $merchantId),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'town' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'business_category' => ['nullable', 'string', 'max:80'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && is_string($this->input('code'))) {
            $this->merge(['code' => Str::upper(trim($this->input('code')))]);
        }
    }
}
