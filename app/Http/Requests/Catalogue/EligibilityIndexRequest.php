<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validated pagination/filter for personnel-service eligibility (Plan §23, §39).
 * Filterable by service or staff ULID and active flag.
 */
final class EligibilityIndexRequest extends FormRequest
{
    public const SORTS = ['created_at', '-created_at'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'service_id' => ['sometimes', 'string', 'size:26'],
            'staff_profile_id' => ['sometimes', 'string', 'size:26'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
