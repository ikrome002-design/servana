<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a Finance MANUAL compensation adjustment (Plan §60/§61, §19.3; Phase 20G). Additive and
 * append-only: it never edits an earned/accrued fact. The schema's provenance CHECK forbids a
 * Finance-created source-linked row (`manual`/`correction` require NULL sources; the `paid_*_reversal`
 * families are SYSTEM-generated negatives), so this endpoint creates a STANDALONE `manual` adjustment —
 * no `source_ledger_ulid` is accepted. `merchant_id`/`branch_id`/`status`/actor fields are
 * server-owned. `branch_id` is derived from the staff profile's primary branch (never client-supplied).
 * Authorization (`compensation.adjustment.create`), fresh MFA step-up, and idempotency are enforced by
 * the route middleware; the high-severity audit is written by the domain action.
 */
final class StoreCompensationAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'staff_profile_ulid' => ['required', 'string', 'size:26'],
            // Signed integer minor units; a zero adjustment is meaningless and rejected (mirrors the
            // DB CHECK amount_minor <> 0). Positive or negative are both financially valid.
            'amount_minor' => ['required', 'integer', Rule::notIn([0])],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            // Server-owned / unsupported fields are explicitly rejected.
            'merchant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'adjustment_type' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'payout_item_id' => ['prohibited'],
            'source_ledger_ulid' => ['prohibited'],
            'source_commission_ledger_id' => ['prohibited'],
            'source_salary_ledger_id' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
