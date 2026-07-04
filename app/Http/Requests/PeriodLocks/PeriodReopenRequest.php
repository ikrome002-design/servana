<?php

declare(strict_types=1);

namespace App\Http\Requests\PeriodLocks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a period reopen REQUEST (Plan §46; ADR-0007; Phase 18B). A mandatory reason
 * is required. Authorization is enforced by the route permission + policy.
 */
final class PeriodReopenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
