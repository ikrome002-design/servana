<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validate a staff invitation (Scope §3.4). Branch is referenced by ULID; a
 * duplicate PENDING invite for the same merchant+email+role+branch is blocked
 * (the DB partial unique index is the backstop).
 */
final class CreateStaffInvitationRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'branch_id' => [
                'required', 'string',
                Rule::exists('merchant_branches', 'ulid')->where('merchant_id', $merchantId),
            ],
            // Invitable roles only — never merchant_admin (Scope §3.2 / §3.4).
            'role' => ['required', 'string', Rule::in([
                MerchantUserRole::BranchManager->value,
                MerchantUserRole::Hr->value,
                MerchantUserRole::Finance->value,
                MerchantUserRole::FrontOffice->value,
                MerchantUserRole::Personnel->value,
                MerchantUserRole::Audit->value,
            ])],
            'role_title' => ['nullable', 'string', 'max:80'],
            'service_eligibility_ids' => ['nullable', 'array'],
            'service_eligibility_ids.*' => ['integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $merchantId = app(TenantContext::class)->merchantId();
            $branchId = MerchantBranch::query()
                ->where('ulid', (string) $this->input('branch_id'))
                ->value('id');

            $duplicate = StaffInvitation::query()
                ->where('merchant_id', $merchantId)
                ->where('email', Str::lower(trim((string) $this->input('email'))))
                ->where('role', (string) $this->input('role'))
                ->where('branch_id', $branchId)
                ->pending()
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('email', 'A pending invitation already exists for this email, role, and branch.');
            }
        });
    }
}
