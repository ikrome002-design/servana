<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reject a percentage platform-fee dispute (Plan §13.10 [Correction 3]; Phase 20E, Increment 6). A
 * mandatory rejection note is required; no money changes. Authorization + fresh step-up + maker/checker
 * are enforced by the route middleware + the action. The request NEVER accepts `status` or `resolved_by`.
 */
final class RejectPlatformFeeDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('resolution_note'))) {
            $this->merge(['resolution_note' => trim($this->input('resolution_note'))]);
        }
    }
}
