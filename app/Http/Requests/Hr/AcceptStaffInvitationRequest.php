<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate staff-invitation acceptance (Scope §3.4). Public (token-based). Phone
 * must be unique among active staff platform-wide (Duplicate Staff Prevention);
 * the DB partial unique index is the backstop.
 */
final class AcceptStaffInvitationRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'first_name' => ['required', 'string', 'min:1', 'max:120'],
            'last_name' => ['required', 'string', 'min:1', 'max:120'],
            'phone' => [
                'required', 'string', 'min:7', 'max:32',
                Rule::unique('staff_profiles', 'phone')->where('is_active', true),
            ],
        ];
    }
}
