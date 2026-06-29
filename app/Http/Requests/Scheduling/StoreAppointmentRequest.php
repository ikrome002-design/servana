<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create-appointment validation (Plan §36). Accepts only safe public identifiers:
 * a branch-scoped client + active service (ULIDs), a scheduled start, and optional
 * preferred/assigned personnel (ULIDs). The backend derives the merchant, branch,
 * end-time (service-duration snapshot), initial status, actor, and timestamps —
 * `merchant_id`, `branch_id` (as ownership), `status`, `ends_at`, `created_by`, and
 * internal database ids are NEVER accepted from the body. `branch_id` is an
 * optional branch ULID used only to disambiguate the write branch.
 */
final class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AppointmentPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'client' => ['required', 'string', 'size:26'],
            'service' => ['required', 'string', 'size:26'],
            'starts_at' => ['required', 'date'],
            'assigned_personnel' => ['sometimes', 'nullable', 'string', 'size:26'],
            'preferred_personnel' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];
    }
}
