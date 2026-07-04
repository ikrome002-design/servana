<?php

declare(strict_types=1);

namespace App\Http\Requests\Refunds;

use App\Domain\Refunds\Enums\RefundStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Refund listing filters (Plan §44; Phase 18B). Read-only; pagination + optional status
 * filter + sort. Tenant/branch scoped by the model scopes.
 */
final class RefundIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RefundPolicy::viewAny + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(array_map(static fn (RefundStatus $s): string => $s->value, RefundStatus::cases()))],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', 'max:40'],
        ];
    }
}
