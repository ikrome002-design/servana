<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Update-service-category validation (Plan §39).
 */
final class UpdateServiceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
