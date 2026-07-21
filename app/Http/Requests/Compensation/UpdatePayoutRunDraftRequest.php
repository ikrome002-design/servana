<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Update an HR DRAFT payout run's period/currency (Plan §62; Phase 20H, §H7). The action re-snapshots
 * the eligible items from the 20G ledgers — the browser supplies only the new period + currency, never
 * items or calculated totals. Allowed only while the run is `draft` (the domain action + state machine
 * fail closed otherwise). Server-owned fields are rejected.
 */
final class UpdatePayoutRunDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'merchant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'status' => ['prohibited'],
            'gross_total_minor' => ['prohibited'],
            'high_value_threshold_snapshot_minor' => ['prohibited'],
            'items' => ['prohibited'],
            'payout_item_ids' => ['prohibited'],
        ];
    }
}
