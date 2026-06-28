<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create-service-category validation (Plan §39). Branch-scoped active-name
 * uniqueness is a DB partial unique index; `branch_id` is an optional branch ULID
 * resolved in the controller.
 */
final class StoreServiceCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
