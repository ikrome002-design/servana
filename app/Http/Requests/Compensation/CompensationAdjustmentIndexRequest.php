<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use App\Domain\Compensation\Enums\CompensationAdjustmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 20G compensation-adjustment listing filters (Plan §60/§61). Read-only, merchant-scoped under
 * `compensation.liability.view`. Every filter is validated; server-owned scope fields are rejected.
 */
final class CompensationAdjustmentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'staff_profile_ulid' => ['sometimes', 'string', 'size:26'],
            'branch_ulid' => ['sometimes', 'string', 'size:26'],
            'adjustment_type' => ['sometimes', 'string', Rule::in(array_map(
                static fn (CompensationAdjustmentType $t): string => $t->value,
                CompensationAdjustmentType::cases(),
            ))],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'merchant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
        ];
    }
}
