<?php

declare(strict_types=1);

namespace App\Http\Requests\Branches;

use App\Domain\Branches\Enums\BranchStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/filter/sort for the branch listing (Plan §23, §24.2).
 * Sorts and the status filter are allowlisted; pagination is bounded (default 25,
 * max 100). Unknown query parameters are ignored.
 */
final class BranchIndexRequest extends FormRequest
{
    /** Allowlisted sort tokens (indexed columns; `-` = descending). */
    public const SORTS = ['name', '-name', 'created_at', '-created_at'];

    public function authorize(): bool
    {
        return true; // route middleware + tenant scope are the authorization boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'status' => ['sometimes', 'string', Rule::in(array_column(BranchStatus::cases(), 'value'))],
        ];
    }
}
