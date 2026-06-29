<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Queue-entry assignment validation (Plan §37; Phase 16B). The assignment mode
 * selects next_available (selector), manual (explicit target ULID), or
 * preferred_personnel (preferred ULID). Overriding a recorded preferred request to
 * a different person requires `reason` (enforced in the action; revalidation runs
 * via the shared scheduling services).
 */
final class AssignQueueEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // QueueEntryPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assignment_mode' => ['required', 'string', 'in:next_available,manual,preferred_personnel'],
            'personnel' => ['required_if:assignment_mode,manual', 'nullable', 'string', 'size:26'],
            'preferred_personnel' => ['required_if:assignment_mode,preferred_personnel', 'nullable', 'string', 'size:26'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
