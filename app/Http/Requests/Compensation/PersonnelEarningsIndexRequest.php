<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Personnel own-scope earnings/payout/query listing (Plan §63; Phase 20H, §H10). Read-only; the acting
 * staff profile is derived server-side from the authenticated membership — a client-supplied
 * `staff_profile_ulid`/`staff_profile_id` is NEVER honoured (own-scope is not selectable). Only
 * pagination is accepted.
 */
final class PersonnelEarningsIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            // Own-scope is derived from the authenticated membership, never chosen by the client.
            'staff_profile_ulid' => ['prohibited'],
            'staff_profile_id' => ['prohibited'],
            'merchant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
        ];
    }
}
