<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Domain\Audit\Enums\AuditExportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated, allowlisted filters for the Audit export list (Plan §11, §13.5; Phase 19).
 * Status is restricted to the lifecycle enum; sort is allowlisted; pagination is bounded.
 */
final class AuditExportIndexRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in(array_map(static fn (AuditExportStatus $s): string => $s->value, AuditExportStatus::cases()))],
            'sort' => ['sometimes', 'string', Rule::in(self::SORTS)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
