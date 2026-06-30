<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/filters for the Front Office service-session list (Plan §23,
 * §25.2; Phase 16C). Filters are indexed columns; sorts are allowlisted. `active=true`
 * returns the bounded live set (pending/in_progress); historical/terminal sessions
 * paginate. Reads are branch-scoped by the model. Phase 22 cross-domain search is out
 * of scope.
 */
final class ServiceSessionIndexRequest extends FormRequest
{
    public const SORTS = ['created_at', '-created_at', 'started_at', '-started_at', 'completed_at', '-completed_at'];

    public function authorize(): bool
    {
        return true; // ServiceSessionPolicy is the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(array_column(ServiceSessionStatus::cases(), 'value'))],
        ];
    }
}
