<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared reason payload for a state-changing promotion / free-period action (Plan §53; Gate C6;
 * Phase 20C). Approve / pause / resume / cancel all require a sanitized, mandatory `change_reason`.
 * Authorization + MFA + fresh step-up are enforced by the route middleware; the controller authorizes
 * via the policy. The request never accepts `status`, `approved_by`, or any authoritative field.
 */
final class ChangeReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'change_reason' => ['required', 'string', 'min:2', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('change_reason'))) {
            $this->merge(['change_reason' => trim($this->input('change_reason'))]);
        }
    }
}
