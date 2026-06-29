<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reschedule-appointment validation (Plan §36). Accepts a new start only; the new
 * end is recomputed from the service-duration snapshot in the action, and the
 * branch calendar + personnel availability are revalidated for the new interval.
 */
final class RescheduleAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AppointmentPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
        ];
    }
}
