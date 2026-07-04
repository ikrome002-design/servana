<?php

declare(strict_types=1);

namespace App\Http\Requests\CashUps;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a cash-up reject / request-correction decision (Plan §45; Phase 18B). A
 * mandatory reason is required for both. Authorization is enforced by the route
 * permission + policy, not here.
 */
final class CashUpDecisionRequest extends FormRequest
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
