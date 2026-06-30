<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use App\Domain\Scheduling\Enums\ServiceSessionStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/filters for a Personnel user's OWN service sessions (Plan §23,
 * §25.2, §19; Phase 16C). No personnel identifier is accepted — own scope is derived
 * from the authenticated context in the controller. Sorts are allowlisted.
 */
final class PersonnelServiceSessionIndexRequest extends FormRequest
{
    public const SORTS = ['created_at', '-created_at', 'started_at', '-started_at', 'completed_at', '-completed_at'];

    public function authorize(): bool
    {
        return true; // own-scope + personnel.my_sessions.view enforced in the controller
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
