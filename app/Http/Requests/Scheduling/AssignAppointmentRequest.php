<?php

declare(strict_types=1);

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Assign-personnel validation (Plan §36). The target personnel is a public staff
 * ULID resolved inside the appointment's branch; eligibility + availability are
 * revalidated by the shared PersonnelSchedulingValidator in the action.
 */
final class AssignAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AppointmentPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'personnel' => ['required', 'string', 'size:26'],
        ];
    }
}
