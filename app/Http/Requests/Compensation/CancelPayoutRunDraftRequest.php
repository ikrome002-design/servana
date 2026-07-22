<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancel an HR draft payout run (Plan §62; Phase 20H, §H5). Bodiless — a draft never claimed a ledger,
 * so there is nothing to release; the action moves `draft → cancelled`. Server-owned fields rejected.
 */
final class CancelPayoutRunDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['prohibited'],
        ];
    }
}
