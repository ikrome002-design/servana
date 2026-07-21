<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Generate (or return the existing) own earnings-statement PDF for a PAID payout item (Plan §63, §65;
 * Phase 20H, §H11; D-H11). Bodiless — the payout item is the ULID route binding, own-scope is derived
 * from the authenticated membership, and generation is idempotent (a second call returns the same
 * file). Server-owned / statement fields are rejected. Authorization (`personnel.my_statements
 * .download`) + billing-mutability are enforced at the route.
 */
final class PersonnelEarningsStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'staff_profile_ulid' => ['prohibited'],
            'staff_profile_id' => ['prohibited'],
            'earnings_statement_file_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
