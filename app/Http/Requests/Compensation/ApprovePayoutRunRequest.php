<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Finance ordinary approval of a payout run (Plan §62; Phase 20H, §H5). Bodiless — the action moves a
 * `finance_verified` run to `approved` (a high-value run sits in `pending_merchant_admin_approval` and
 * is guarded off this path). Server-owned fields rejected; authorization + fresh step-up +
 * Idempotency-Key at the route.
 */
final class ApprovePayoutRunRequest extends FormRequest
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
            'approved_by' => ['prohibited'],
            'gross_total_minor' => ['prohibited'],
        ];
    }
}
