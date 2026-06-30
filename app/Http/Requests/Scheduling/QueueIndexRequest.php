<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use App\Domain\Scheduling\Enums\QueueAssignmentMode;
use App\Domain\Scheduling\Enums\QueueEntryStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/filters for the branch queue list (Plan §23, §37). Filters
 * are indexed columns; references are public ULIDs resolved inside tenant + branch
 * scope. Sorts are allowlisted. `active=true` returns the bounded live board;
 * historical/terminal records paginate. Phase 22 cross-domain/fuzzy search is out
 * of scope.
 */
final class QueueIndexRequest extends FormRequest
{
    public const SORTS = ['position', '-position', 'queued_at', '-queued_at', 'created_at', '-created_at'];

    public function authorize(): bool
    {
        return true; // QueueEntryPolicy is the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(array_column(QueueEntryStatus::cases(), 'value'))],
            'assignment_mode' => ['sometimes', 'string', Rule::in(array_column(QueueAssignmentMode::cases(), 'value'))],
            'service' => ['sometimes', 'string', 'size:26'],
            'assigned_personnel' => ['sometimes', 'string', 'size:26'],
            'position' => ['sometimes', 'integer', 'min:1'],
            'queued_from' => ['sometimes', 'date_format:Y-m-d'],
            'queued_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:queued_from'],
        ];
    }
}
