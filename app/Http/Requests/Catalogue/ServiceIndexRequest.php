<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use App\Domain\Catalogue\Enums\ServiceStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/filter/sort for the service catalogue (Plan §23). Sorts
 * and the status filter are allowlisted; `q` matches the service name (the
 * catalogue is non-sensitive, unlike client search).
 */
final class ServiceIndexRequest extends FormRequest
{
    public const SORTS = ['name', '-name', 'price_minor', '-price_minor', 'created_at', '-created_at'];

    public function authorize(): bool
    {
        return true; // ServicePolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'status' => ['sometimes', 'string', Rule::in(array_column(ServiceStatus::cases(), 'value'))],
            'category_id' => ['sometimes', 'string', 'size:26'],
            'q' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
