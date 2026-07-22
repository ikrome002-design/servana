<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use App\Domain\Compensation\Enums\EarningsQuerySubjectType;
use App\Domain\Compensation\Enums\EarningsQueryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Personnel raise an OWN-SCOPE earnings query (Plan §63; Phase 20H, §H12). The caller supplies the
 * subject (type + public ULID of one of their OWN facts — commission/salary ledger row or payout item),
 * the query type, and the message body. The subject is validated to belong to the acting staff profile
 * IN THE DOMAIN ACTION (a foreign/non-existent subject 404s with no existence leak). `staff_profile_id`,
 * `merchant_id`/`branch_id`, `status`, `assigned_role`/`assigned_to`, and the resolved `subject_id` are
 * all server-owned. Authorization (`personnel.my_earnings_query.create`) is enforced at the route.
 */
final class StoreEarningsQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', Rule::in(EarningsQuerySubjectType::values())],
            'subject_ulid' => ['required', 'string', 'size:26'],
            'query_type' => ['required', 'string', Rule::in(EarningsQueryType::values())],
            'body' => ['required', 'string', 'min:3', 'max:2000'],
            // Server-owned fields are explicitly rejected.
            'staff_profile_id' => ['prohibited'],
            'staff_profile_ulid' => ['prohibited'],
            'subject_id' => ['prohibited'],
            'merchant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'status' => ['prohibited'],
            'assigned_role' => ['prohibited'],
            'assigned_to' => ['prohibited'],
            'resolved_adjustment_id' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => trim($this->input('body'))]);
        }
    }
}
