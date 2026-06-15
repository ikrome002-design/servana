<?php

declare(strict_types=1);

namespace App\Http\Requests\Hr;

use App\Domain\Auth\Enums\PermissionOverrideEffect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate a permission-override request (Plan §10.3). The capability key must
 * exist in the seeded catalogue; authority + grantability + anti-escalation are
 * enforced by PermissionOverrideService.
 */
final class StorePermissionOverrideRequest extends FormRequest
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
            'permission' => ['required', 'string', Rule::exists('permissions', 'key')],
            'effect' => ['required', 'string', Rule::in([
                PermissionOverrideEffect::Grant->value,
                PermissionOverrideEffect::Deny->value,
            ])],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
