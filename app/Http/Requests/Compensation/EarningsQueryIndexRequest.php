<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use App\Domain\Compensation\Enums\EarningsQueryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Earnings-query listing filters (Plan §63; Phase 20H). Shared by the personnel own-scope index and the
 * Finance responder work-queue index; the scope (own vs merchant) is server-authoritative in the
 * controller, never widened by a client filter. Read-only.
 */
final class EarningsQueryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(EarningsQueryStatus::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'staff_profile_ulid' => ['prohibited'],
            'staff_profile_id' => ['prohibited'],
            'merchant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
        ];
    }
}
