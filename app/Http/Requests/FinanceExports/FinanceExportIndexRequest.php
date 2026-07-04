<?php

declare(strict_types=1);

namespace App\Http\Requests\FinanceExports;

use App\Domain\FinanceOps\Enums\FinanceExportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate finance export list filters (Plan §65, §67; Phase 18B).
 */
final class FinanceExportIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(array_map(static fn (FinanceExportStatus $s): string => $s->value, FinanceExportStatus::cases()))],
            'sort' => ['sometimes', 'string', 'max:64'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
