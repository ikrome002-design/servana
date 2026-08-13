<?php

declare(strict_types=1);

namespace App\Http\Requests\Branches;

use App\Domain\Audit\Enums\AuditSeverity;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters for Branch Manager's masked, assigned-branch audit timeline. */
final class BranchAuditVisibilityIndexRequest extends FormRequest
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
            'severity' => ['sometimes', 'string', Rule::in(array_column(AuditSeverity::cases(), 'value'))],
            'action' => ['sometimes', 'string', 'max:160'],
        ];
    }
}
