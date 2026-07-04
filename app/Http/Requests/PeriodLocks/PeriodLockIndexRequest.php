<?php

declare(strict_types=1);

namespace App\Http\Requests\PeriodLocks;

use App\Domain\FinanceOps\Enums\FinancialPeriodLockStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate financial period lock list filters (Plan §46; Phase 18B).
 */
final class PeriodLockIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(array_map(static fn (FinancialPeriodLockStatus $s): string => $s->value, FinancialPeriodLockStatus::cases()))],
            'sort' => ['sometimes', 'string', 'max:64'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
