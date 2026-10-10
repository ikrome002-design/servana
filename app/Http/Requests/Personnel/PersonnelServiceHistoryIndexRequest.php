<?php

declare(strict_types=1);

namespace App\Http\Requests\Personnel;

use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters for the authenticated Personnel member's own service history (Phase UI-14). */
final class PersonnelServiceHistoryIndexRequest extends FormRequest
{
    private const SORTS = ['completed_at', '-completed_at', 'started_at', '-started_at'];

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
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'service' => ['sometimes', 'string', 'size:26'],
            'status' => ['sometimes', 'string', Rule::in([
                ServiceSessionStatus::Completed->value,
                ServiceSessionStatus::Cancelled->value,
            ])],
        ];
    }
}
