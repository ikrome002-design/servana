<?php

declare(strict_types=1);

namespace App\Http\Requests\Receipts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Receipt listing filters (Plan §43; Phase 18B). Read-only; pagination + optional
 * invoice ULID filter + sort. The listing is tenant/branch scoped by the model scopes.
 */
final class ReceiptIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ReceiptPolicy::viewAny + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice' => ['sometimes', 'string', 'size:26'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', 'max:40'],
        ];
    }
}
