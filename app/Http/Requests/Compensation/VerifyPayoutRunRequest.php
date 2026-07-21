<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Finance verify a submitted payout run (Plan §62; Phase 20H, §H5). Bodiless — the action moves
 * `submitted → finance_verified` and auto-routes a high-value run to `pending_merchant_admin_approval`
 * by comparing the run gross against the SNAPSHOTTED threshold (never a client value). Server-owned
 * fields are rejected. Authorization + fresh step-up + Idempotency-Key are enforced at the route.
 */
final class VerifyPayoutRunRequest extends FormRequest
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
            'verified_by' => ['prohibited'],
            'high_value_threshold_snapshot_minor' => ['prohibited'],
            'gross_total_minor' => ['prohibited'],
        ];
    }
}
