<?php

declare(strict_types=1);

namespace App\Http\Requests\Branches;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validate a branch profile update (Scope §3.3). Code stays unique per merchant,
 * ignoring the current branch.
 */
final class UpdateBranchRequest extends FormRequest
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
        $branch = $this->route('branch');
        $branchId = $branch instanceof MerchantBranch ? $branch->id : null;

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'code' => [
                'sometimes', 'required', 'string', 'min:2', 'max:20', 'alpha_dash',
                Rule::unique('merchant_branches', 'code')
                    ->where('merchant_id', $merchantId)
                    ->ignore($branchId),
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
