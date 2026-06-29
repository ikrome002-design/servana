<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Appointment-to-queue conversion validation (Plan §25.2, §37; Phase 16B). The
 * assignment mode defaults to next_available; manual requires a target ULID and
 * preferred_personnel a preferred ULID. The appointment is resolved by its ULID
 * route binding (tenant + branch scope); merchant/branch/status/position are derived
 * server-side.
 */
final class ConvertAppointmentToQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // QueueEntryPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assignment_mode' => ['sometimes', 'nullable', 'string', 'in:next_available,manual,preferred_personnel'],
            'personnel' => ['required_if:assignment_mode,manual', 'nullable', 'string', 'size:26'],
            'preferred_personnel' => ['required_if:assignment_mode,preferred_personnel', 'nullable', 'string', 'size:26'],
        ];
    }
}
