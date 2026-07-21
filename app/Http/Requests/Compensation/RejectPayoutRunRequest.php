<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reject a pre-paid payout run (Plan §62; Phase 20H, §H5). Carries only the mandatory rejection reason;
 * the action releases every claimed ledger row (clears `payout_item_id`) and moves the run to
 * `rejected`. Corrections are a new draft/run — never a silent line edit. Server-owned fields rejected.
 */
final class RejectPayoutRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'status' => ['prohibited'],
            'rejected_by' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
