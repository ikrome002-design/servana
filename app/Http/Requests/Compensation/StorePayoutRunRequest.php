<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create an HR draft payout run (Plan §62; Phase 20H, §H6/§H7). The caller supplies ONLY the target
 * branch (public ULID), the pay period, and the single run currency — every other field is
 * server-owned: `merchant_id`/`branch_id` are derived from the resolved branch inside tenant scope; the
 * `status`, `gross_total_minor`, actor columns, and the high-value threshold snapshot are set by the
 * domain action; the eligible items are SNAPSHOTTED server-side from the 20G ledgers (the browser never
 * supplies items or calculated totals). A run is single-currency (§D-H3-1). Authorization
 * (`payout_run.create`) + branch scope are enforced at the route + controller.
 */
final class StorePayoutRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_ulid' => ['required', 'string', 'size:26'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            // Server-owned / snapshot fields are explicitly rejected.
            'merchant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'status' => ['prohibited'],
            'gross_total_minor' => ['prohibited'],
            'high_value_threshold_snapshot_minor' => ['prohibited'],
            'created_by' => ['prohibited'],
            'submitted_by' => ['prohibited'],
            'verified_by' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'paid_by' => ['prohibited'],
            'paid_at' => ['prohibited'],
            'external_payment_reference' => ['prohibited'],
            'items' => ['prohibited'],
            'payout_item_ids' => ['prohibited'],
        ];
    }
}
