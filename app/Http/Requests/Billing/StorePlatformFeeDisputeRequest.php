<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Raise a percentage platform-fee dispute (Plan §13.10 [Correction 3]; Phase 20E, Increment 6).
 * Targets a platform-fee ledger entry and/or a subscription invoice by PUBLIC ULID (at least one),
 * with a mandatory sanitized reason and an optional private evidence file (Phase 10F ULID). The
 * controller resolves the ULIDs within the tenant scope (a foreign-tenant ULID → 404); the request
 * NEVER accepts `merchant_id`, `branch_id`, `status`, `created_by`, or any server-owned field.
 */
final class StorePlatformFeeDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'platform_fee_ledger_entry' => ['nullable', 'string', 'required_without:subscription_invoice'],
            'subscription_invoice' => ['nullable', 'string', 'required_without:platform_fee_ledger_entry'],
            'reason' => ['required', 'string', 'min:2', 'max:2000'],
            'evidence_file' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
