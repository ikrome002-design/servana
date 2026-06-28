<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create-service validation (Plan §39). Money is integer minor units (≥0);
 * currency is an allowlisted uppercase ISO code (KES default); duration is whole
 * minutes (>0). `category_id`/`branch_id` are branch ULIDs resolved + ownership-
 * checked in the controller.
 */
final class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ServicePolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'string', 'size:26'],
            'branch_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', Rule::in(array_column(Currency::cases(), 'value'))],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
