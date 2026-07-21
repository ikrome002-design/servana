<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Merchant-Administrator high-value approval of a payout run (Plan §62; Phase 20H, §H5). Bodiless — the
 * action moves `pending_merchant_admin_approval → approved`, reachable only after Finance verification
 * routed the run there because gross exceeded the snapshotted threshold. Server-owned fields rejected;
 * authorization + fresh step-up + Idempotency-Key at the route.
 */
final class ApproveHighValuePayoutRunRequest extends FormRequest
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
            'high_value_threshold_snapshot_minor' => ['prohibited'],
        ];
    }
}
