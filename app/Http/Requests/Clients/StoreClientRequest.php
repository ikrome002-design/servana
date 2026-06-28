<?php

declare(strict_types=1);

namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create-client validation (Plan §35). Phone is required and normalized/encrypted/
 * indexed in the action; email is optional and encrypted. `branch_id` is an
 * optional branch ULID resolved in the controller.
 */
final class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'full_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'min:7', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
