<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Update-client validation (Plan §35). All fields optional; a changed phone is
 * re-normalized/re-indexed and re-checked for same-branch duplicates in the action.
 */
final class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:160'],
            'phone' => ['sometimes', 'string', 'min:7', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
