<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Assign-eligibility validation (Plan §39). The service comes from the route
 * binding (`/services/{service}/eligibility`); the body carries the personnel
 * staff ULID. Same-branch/same-tenant linkage is enforced in AssignEligibility +
 * the composite FKs.
 */
final class StoreEligibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'staff_profile_id' => ['required', 'string', 'size:26'],
        ];
    }
}
