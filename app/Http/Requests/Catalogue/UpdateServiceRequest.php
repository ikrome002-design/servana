<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update-service validation (Plan §39). All fields optional; the same money/
 * currency/duration invariants apply. The legacy preferred-personnel fee is not
 * accepted here (non-editable seam).
 */
final class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'string', 'size:26'],
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price_minor' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', Rule::in(array_column(Currency::cases(), 'value'))],
            'duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
