<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use App\Domain\Scheduling\Enums\AppointmentStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/filters for the Front Office appointment list (Plan §23,
 * §36). Filters are indexed columns; references are public ULIDs resolved inside
 * tenant + branch scope. Sorts are allowlisted (Phase 22 cross-domain/fuzzy
 * search is out of scope).
 */
final class AppointmentIndexRequest extends FormRequest
{
    public const SORTS = ['starts_at', '-starts_at', 'created_at', '-created_at'];

    public function authorize(): bool
    {
        return true; // AppointmentPolicy is the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['sometimes', 'string', Rule::in(array_column(AppointmentStatus::cases(), 'value'))],
            'client' => ['sometimes', 'string', 'size:26'],
            'service' => ['sometimes', 'string', 'size:26'],
            'assigned_personnel' => ['sometimes', 'string', 'size:26'],
        ];
    }
}
