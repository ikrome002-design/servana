<?php

declare(strict_types=1);

namespace App\Http\Requests\PeriodLocks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a financial period lock creation (Plan §46; Phase 18B). Finance supplies the
 * period range and optional branch scope (null = merchant-wide) and optional
 * exception-required flag. Authorization is enforced by the route permission + policy.
 */
final class CreateFinancialPeriodLockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch' => ['sometimes', 'nullable', 'string', 'size:26'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'exception_required' => ['sometimes', 'boolean'],
        ];
    }
}
