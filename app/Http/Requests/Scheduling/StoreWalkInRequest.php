<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create-walk-in validation (Plan §37; Phase 16B). Accepts only safe public
 * identifiers: an existing branch-client ULID OR the complete new-client fields, a
 * service ULID, an assignment mode, optional target/preferred personnel ULIDs, and
 * an optional estimated-wait override (value + reason together). The backend derives
 * merchant, branch, queue position, status, estimate, actor, and timestamps —
 * `merchant_id`, `branch_id` (as ownership), `status`, position, `created_by`,
 * internal ids, and any preferred-personnel fee are NEVER accepted from the body.
 */
final class StoreWalkInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // QueueEntryPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'assignment_mode' => ['required', 'string', 'in:next_available,manual,preferred_personnel'],
            'service' => ['required', 'string', 'size:26'],
            'personnel' => ['required_if:assignment_mode,manual', 'nullable', 'string', 'size:26'],
            'preferred_personnel' => ['required_if:assignment_mode,preferred_personnel', 'nullable', 'string', 'size:26'],
            'estimated_wait_override_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1440'],
            'estimated_wait_override_reason' => ['required_with:estimated_wait_override_minutes', 'nullable', 'string', 'max:500'],

            // Client: an existing ULID or a complete new-client object (exactly one).
            'client' => ['required_without:new_client', 'nullable', 'string', 'size:26'],
            'new_client' => ['required_without:client', 'nullable', 'array'],
            'new_client.full_name' => ['required_with:new_client', 'string', 'max:255'],
            'new_client.phone' => ['required_with:new_client', 'string', 'max:32'],
            'new_client.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'new_client.notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
