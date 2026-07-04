<?php

declare(strict_types=1);

namespace App\Http\Requests\FinanceExports;

use App\Domain\FinanceOps\Enums\FinanceExportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate a finance export request (Plan §65, §67; Phase 18B). Any enumerated type is
 * accepted at validation; unsupported types (compensation/payouts/billing) are rejected
 * by the action with `422 unsupported_export_type` (a distinct, safe code). A mandatory
 * reason is required; the optional branch scope + filters are validated.
 */
final class RequestFinanceExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'export_type' => ['required', 'string', Rule::in(array_map(static fn (FinanceExportType $t): string => $t->value, FinanceExportType::cases()))],
            'branch' => ['sometimes', 'nullable', 'string', 'size:26'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'filters' => ['sometimes', 'array'],
        ];
    }
}
