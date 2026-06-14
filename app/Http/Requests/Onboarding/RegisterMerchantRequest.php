<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validates Merchant Administrator self-registration (Scope §3.2).
 *
 * Public endpoint (no auth). Deliberately minimal + safe: owner name, email,
 * business name. No KYC / compliance fields exist (Scope §3.1 exclusion). Email
 * uniqueness is NOT asserted here — duplicate emails are handled uniformly in
 * the action so this endpoint cannot enumerate existing accounts.
 */
final class RegisterMerchantRequest extends FormRequest
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
            'owner_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'business_name' => ['required', 'string', 'min:2', 'max:160'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function ownerName(): string
    {
        return (string) $this->validated()['owner_name'];
    }

    public function email(): string
    {
        return (string) $this->validated()['email'];
    }

    public function businessName(): string
    {
        return (string) $this->validated()['business_name'];
    }
}
