<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate the platform SMS usage query (COR-UI08-001 §9; Phase UI-08).
 *
 * `merchant` is a public ULID; the internal id is never accepted or exposed. `per_page` is bounded
 * so a platform collection cannot be asked for an unbounded page (Plan §11 pagination rule).
 */
final class SmsBillingUsageQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'merchant' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
