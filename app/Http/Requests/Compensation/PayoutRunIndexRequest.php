<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use App\Domain\Compensation\Enums\PayoutRunStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 20H payout-run listing filters (Plan §62; shared by the HR / Finance / Merchant-Admin index
 * routes). Read-only; the route `EnsurePermission` + policy are the authorization boundary and
 * tenant/branch scope is server-authoritative (a branch-scoped actor is narrowed to its assigned
 * branches in the controller). Every filter is validated; a client-supplied `merchant_id`/`branch_id`
 * is never honoured (branch filtering uses the public `branch_ulid`).
 */
final class PayoutRunIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(PayoutRunStatus::values())],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'branch_ulid' => ['sometimes', 'string', 'size:26'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            // Server-owned scope fields are never accepted from the client.
            'merchant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
        ];
    }
}
