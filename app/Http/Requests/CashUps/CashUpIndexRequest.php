<?php

declare(strict_types=1);

namespace App\Http\Requests\CashUps;

use App\Domain\Branches\Enums\CashUpStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate cash-up list filters (Plan §45; Phase 18B). Finance review inbox: optional
 * status / business-date filters and sort. Pagination is applied by ApiPagination.
 */
final class CashUpIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(CashUpStatus::values(CashUpStatus::cases()))],
            'business_date' => ['sometimes', 'date_format:Y-m-d'],
            'sort' => ['sometimes', 'string', 'max:64'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
